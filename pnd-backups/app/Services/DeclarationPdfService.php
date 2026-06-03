<?php

namespace App\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use MongoDB\Collection;
use RuntimeException;

class DeclarationPdfService
{
    public function __construct(private InstanceDiscovery $discovery) {}

    /**
     * Descarga el PDF de una declaración individual llamando al
     * microservicio de reportes de la instancia.
     *
     * @return string  Bytes del PDF
     */
    public function getPdf(string $slug, string $declaracionId): string
    {
        $inst = $this->resolveInst($slug);

        $decl = $this->col($inst, 'declaraciones')
            ->findOne(['_id' => new ObjectId($declaracionId)]);

        if (! $decl) {
            throw new RuntimeException("Declaración {$declaracionId} no encontrada.");
        }

        $user = null;
        if (isset($decl['owner'])) {
            $user = $this->col($inst, 'users')
                ->findOne(['_id' => $decl['owner']]);
        }

        $insData = $this->resolveInsData($inst, $decl, $user);

        return $this->callReportsService($inst, $decl, $user, $insData);
    }

    /**
     * Genera un ZIP con los PDFs de todas las declaraciones dadas.
     *
     * @param  array  $rows  Filas del informe (con campo '_id')
     * @return string  Ruta al archivo ZIP temporal (se borra con deleteFileAfterSend)
     */
    public function buildZip(string $slug, array $rows): string
    {
        $inst    = $this->resolveInst($slug);
        $zipPath = tempnam(sys_get_temp_dir(), 'pnd_zip_') . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP temporal.');
        }

        $errors = [];

        foreach ($rows as $i => $row) {
            $id = $row['_id'] ?? null;
            if (! $id) continue;

            try {
                $decl = $this->col($inst, 'declaraciones')
                    ->findOne(['_id' => new ObjectId($id)]);

                if (! $decl) continue;

                $user = null;
                if (isset($decl['owner'])) {
                    $user = $this->col($inst, 'users')
                        ->findOne(['_id' => $decl['owner']]);
                }

                $insData  = $this->resolveInsData($inst, $decl, $user);
                $pdf      = $this->callReportsService($inst, $decl, $user, $insData);
                $filename = $this->buildFilename($i + 1, $row);
                $zip->addFromString($filename, $pdf);

            } catch (\Throwable $e) {
                $errors[] = "#{$id}: " . $e->getMessage();
            }
        }

        if (! empty($errors)) {
            $zip->addFromString('_errores.txt', implode("\n", $errors));
        }

        $zip->close();
        return $zipPath;
    }

    // ── institucionData ────────────────────────────────────────────────────────

    /**
     * Resuelve el institucionData que necesita el servicio de reportes.
     *
     * Estrategia (igual que el backend Node.js):
     *  1. Para declaraciones firmadas: busca en la colección `users_dec`
     *     el registro que guarda el acuse original.
     *  2. Fallback (no firmada o sin registro): lee instituciones.json del
     *     backend de la instancia y extrae los campos por clave + tipo.
     */
    private function resolveInsData(array $inst, $decl, $user): array
    {
        $firmada = (bool) ($decl['firmada'] ?? false);
        $declArr = $this->normalize($decl);

        // 1. Declaración firmada → busca registro en users_dec
        if ($firmada) {
            $userDec = $this->col($inst, 'users_dec')
                ->findOne(['declaraciones' => $decl['_id']]);

            if ($userDec) {
                $ud = $this->normalize($userDec);
                return array_intersect_key($ud, array_flip([
                    'ente_publico', 'lugar', 'servidor_publico_recibe', 'acuse', 'declaracion',
                ]));
            }
        }

        // 2. Fallback: lee instituciones.json del backend de la instancia
        return $this->insDataFromJson(
            $inst['slug'],
            (string) ($user['institucion']['clave'] ?? ''),
            (string) ($declArr['tipoDeclaracion'] ?? ''),
        );
    }

    /**
     * Lee instituciones.json y extrae el insData para la clave e tipo dados.
     */
    private function insDataFromJson(string $slug, string $clave, string $tipo): array
    {
        $path = config('backups.instances_path') . "/{$slug}/SistemaDeclaraciones_backend/src/data/instituciones.json";

        if (! is_file($path)) {
            throw new RuntimeException("No se encontró instituciones.json en la instancia '{$slug}'.");
        }

        $instituciones = json_decode(file_get_contents($path), true);
        if (! $instituciones) {
            throw new RuntimeException("No se pudo leer instituciones.json de la instancia '{$slug}'.");
        }

        $match = null;
        foreach ($instituciones as $inst) {
            if (($inst['clave'] ?? '') === $clave) {
                $match = $inst;
                break;
            }
        }

        // Si no hay coincidencia exacta, usa el primero disponible
        if (! $match) {
            $match = $instituciones[0] ?? null;
        }

        if (! $match) {
            throw new RuntimeException("No se encontró institución con clave '{$clave}' en instituciones.json.");
        }

        $tipoKey = match (strtoupper($tipo)) {
            'INICIAL'      => 'inicial',
            'MODIFICACION' => 'modificacion',
            'CONCLUSION'   => 'conclusion',
            default        => 'inicial',
        };

        return [
            'ente_publico'             => $match['ente_publico'] ?? '',
            'lugar'                    => $match['lugar'] ?? '',
            'servidor_publico_recibe'  => $match['servidor_publico_recibe'] ?? [],
            'acuse'                    => $match['acuse'][$tipoKey] ?? [],
            'declaracion'              => array_merge(
                $match['declaracion'][$tipoKey] ?? [],
                ['subtitulo' => $match['declaracion']['subtitulo'] ?? ''],
            ),
        ];
    }

    // ── Llamada HTTP al microservicio ──────────────────────────────────────────

    private function callReportsService(array $inst, $decl, $user, array $insData): string
    {
        $reportsUrl = $inst['reports_url'] ?? null;
        $apiKey     = $inst['reports_key'] ?? null;

        if (! $reportsUrl || ! $apiKey) {
            throw new RuntimeException(
                "Esta instancia no tiene REPORTS_URL / REPORTS_API_KEY configurados."
            );
        }

        $declArr = $this->normalize($decl);
        $userArr = $user ? $this->normalize($user) : [];

        foreach (['password', 'refreshJwtToken', 'resetToken', '__v'] as $f) {
            unset($userArr[$f]);
        }

        $payload = json_encode([
            'owner'          => $userArr,
            'institucionData'=> $insData,
            'id'             => $declArr['_id'] ?? '',
            'declaracion'    => $declArr,
            'preliminar'     => ! ($declArr['firmada'] ?? false),
            'publico'        => false,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($reportsUrl . '/acuse-declaracion');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'X-Api-Key: ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException("Error de red al contactar el servicio de reportes: {$curlErr}");
        }
        if ($httpCode !== 200) {
            $preview = is_string($result) ? substr($result, 0, 200) : '';
            throw new RuntimeException("Servicio de reportes devolvió HTTP {$httpCode}. Respuesta: {$preview}");
        }
        if (empty($result)) {
            throw new RuntimeException("El servicio de reportes devolvió una respuesta vacía.");
        }

        return $result;
    }

    // ── Conversión BSON → array PHP / JSON-nativo ──────────────────────────────

    /**
     * Convierte un documento BSON a array PHP con tipos JSON-nativos:
     *   ObjectId    → string hex
     *   UTCDateTime → ISO 8601 string
     */
    private function normalize(mixed $doc): array
    {
        $arr = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
        return $this->flattenBson($arr);
    }

    private function flattenBson(mixed $val): mixed
    {
        if (! is_array($val)) return $val;

        // ObjectId extendido: {"$oid": "..."}
        if (array_key_exists('$oid', $val) && count($val) === 1) {
            return $val['$oid'];
        }

        // UTCDateTime: {"$date": {"$numberLong": "..."}}
        if (array_key_exists('$date', $val) && count($val) === 1) {
            $d  = $val['$date'];
            $ms = is_array($d) ? (int) ($d['$numberLong'] ?? 0) : (int) $d;
            $sec   = intdiv($ms, 1000);
            $msPad = str_pad($ms % 1000, 3, '0', STR_PAD_LEFT);
            return gmdate('Y-m-d\TH:i:s', $sec) . '.' . $msPad . 'Z';
        }

        return array_map(fn($v) => $this->flattenBson($v), $val);
    }

    // ── Utilidades ─────────────────────────────────────────────────────────────

    private function buildFilename(int $n, array $row): string
    {
        $parts = array_filter([
            str_pad($n, 4, '0', STR_PAD_LEFT),
            $this->slug($row['curp']             ?? ''),
            $this->slug($row['tipoDeclaracion']  ?? ''),
            $row['anioEjercicio'] ?? '',
        ]);
        return implode('_', $parts) . '.pdf';
    }

    private function slug(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', mb_strtoupper($s));
    }

    private function resolveInst(string $slug): array
    {
        $inst = $this->discovery->find($slug);
        if (! $inst) {
            throw new RuntimeException("Instancia '{$slug}' no encontrada.");
        }
        return $inst;
    }

    private function col(array $inst, string $name): Collection
    {
        $uri = sprintf(
            'mongodb://%s:%s@%s:%d/?authSource=admin',
            rawurlencode($inst['mongo_user']),
            rawurlencode($inst['mongo_pass']),
            $inst['mongo_host'],
            (int) $inst['mongo_port'],
        );
        return (new Client($uri))->selectCollection($inst['mongo_db'], $name);
    }
}
