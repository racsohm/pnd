<?php

namespace App\Services;

use RuntimeException;

/**
 * Lee/escribe el bloque editable de
 *   <instances_path>/<slug>/SistemaDeclaraciones_backend/src/data/instituciones.json
 *
 * Solo toca los campos que el asistente pregunta: ente_publico, clave, lugar,
 * servidor_publico_recibe.{nombre,cargo}. Preserva todo lo demás (acuse,
 * declaracion, etc.) leyendo el JSON existente y haciendo merge.
 */
class InstituteService
{
    private const JSON_REL = 'SistemaDeclaraciones_backend/src/data/instituciones.json';
    private const EXAMPLE_REL = 'SistemaDeclaraciones_backend/src/data/instituciones.json.example';

    public function __construct(private InstanceDiscovery $discovery) {}

    public function path(string $slug): string
    {
        $base = (string) config('backups.instances_path');
        return $base.'/'.$slug.'/'.self::JSON_REL;
    }

    /** Devuelve los 5 campos editables. Si el archivo no existe, defaults vacíos. */
    public function read(string $slug): array
    {
        if (! $this->discovery->find($slug)) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }

        $file = $this->path($slug);
        $raw  = null;

        if (is_file($file) && is_readable($file)) {
            $raw = $this->decode($file);
        } else {
            $example = dirname($this->path($slug)).'/'.basename(self::EXAMPLE_REL);
            if (is_file($example) && is_readable($example)) {
                $raw = $this->decode($example);
            }
        }

        $first = is_array($raw) && isset($raw[0]) && is_array($raw[0]) ? $raw[0] : [];

        return [
            'ente_publico' => (string) ($first['ente_publico'] ?? ''),
            'clave'        => (string) ($first['clave'] ?? ''),
            'lugar'        => (string) ($first['lugar'] ?? ''),
            'nombre'       => (string) data_get($first, 'servidor_publico_recibe.nombre', ''),
            'cargo'        => (string) data_get($first, 'servidor_publico_recibe.cargo', ''),
        ];
    }

    /**
     * Reemplaza los 5 campos editables y persiste el JSON. Conserva el resto
     * de la estructura (acuse/declaracion) si el archivo ya existía; si no,
     * arranca del .example o de un esqueleto mínimo.
     */
    public function write(string $slug, array $fields): void
    {
        if (! $this->discovery->find($slug)) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }

        $file = $this->path($slug);
        $dir  = dirname($file);

        if (! is_dir($dir)) {
            throw new RuntimeException("Falta el directorio del backend: $dir");
        }
        if (! is_writable($dir) && ! (is_file($file) && is_writable($file))) {
            throw new RuntimeException(
                "Sin permisos de escritura en $dir. ".
                "Verificá que el mount /host/instances sea rw en docker-compose.yml."
            );
        }

        // Base: el JSON actual o, en su defecto, el .example.
        $base = null;
        if (is_file($file)) {
            $base = $this->decode($file);
        }
        if (! is_array($base) || ! isset($base[0])) {
            $example = $dir.'/'.basename(self::EXAMPLE_REL);
            if (is_file($example)) {
                $base = $this->decode($example);
            }
        }
        if (! is_array($base) || ! isset($base[0]) || ! is_array($base[0])) {
            $base = [[]];
        }

        $base[0]['ente_publico'] = $fields['ente_publico'];
        $base[0]['clave']        = $fields['clave'];
        $base[0]['lugar']        = $fields['lugar'];
        $base[0]['servidor_publico_recibe'] = array_merge(
            $base[0]['servidor_publico_recibe'] ?? [],
            [
                'nombre' => $fields['nombre'],
                'cargo'  => $fields['cargo'],
            ]
        );

        $json = json_encode(
            $base,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            throw new RuntimeException('json_encode falló: '.json_last_error_msg());
        }

        $tmp = $file.'.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException("No se pudo escribir $tmp");
        }
        if (! rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("No se pudo renombrar $tmp → $file");
        }
    }

    private function decode(string $file): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("No se pudo leer $file");
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException("JSON inválido en $file: ".json_last_error_msg());
        }
        return $data;
    }
}
