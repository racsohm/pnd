<?php

namespace App\Services;

use Carbon\Carbon;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use RuntimeException;

class ReportService
{
    public function __construct(private InstanceDiscovery $discovery) {}

    /**
     * Devuelve las declaraciones creadas en el rango [$from, $to] enriquecidas
     * con los datos del declarante (nombre, curp, rfc, username).
     * Límite: 5 000 filas por consulta.
     */
    public function getRows(string $slug, Carbon $from, Carbon $to): array
    {
        $decls = $this->col($slug, 'declaraciones')->find(
            [
                'createdAt' => [
                    '$gte' => new UTCDateTime($from),
                    '$lte' => new UTCDateTime($to),
                ],
            ],
            [
                'projection' => [
                    '_id'                 => 1,
                    'owner'               => 1,
                    'tipoDeclaracion'     => 1,
                    'anioEjercicio'       => 1,
                    'firmada'             => 1,
                    'declaracionCompleta' => 1,
                    'createdAt'           => 1,
                ],
                'sort'  => ['createdAt' => -1],
                'limit' => 5000,
            ],
        )->toArray();

        if (empty($decls)) {
            return [];
        }

        // Índice owner ObjectId → ObjectId (dedupado)
        $ownerMap = [];
        foreach ($decls as $d) {
            if (isset($d['owner'])) {
                $ownerMap[(string) $d['owner']] = $d['owner'];
            }
        }

        // Carga masiva de usuarios
        $usersById = [];
        if ($ownerMap) {
            foreach ($this->col($slug, 'users')->find(
                ['_id' => ['$in' => array_values($ownerMap)]],
                ['projection' => [
                    'nombre'          => 1,
                    'primerApellido'  => 1,
                    'segundoApellido' => 1,
                    'curp'            => 1,
                    'rfc'             => 1,
                    'username'        => 1,
                ]],
            ) as $u) {
                $usersById[(string) $u['_id']] = $u;
            }
        }

        $tz = config('app.timezone', 'UTC');

        $rows = [];
        foreach ($decls as $d) {
            $ownerId = isset($d['owner']) ? (string) $d['owner'] : null;
            $user    = $ownerId ? ($usersById[$ownerId] ?? null) : null;

            $fecha = '—';
            if (isset($d['createdAt']) && $d['createdAt'] instanceof UTCDateTime) {
                $fecha = Carbon::instance($d['createdAt']->toDateTime())
                    ->setTimezone($tz)
                    ->format('Y-m-d H:i');
            }

            $nombre = '—';
            if ($user) {
                $partes = array_filter([
                    $user['nombre']          ?? null,
                    $user['primerApellido']  ?? null,
                    $user['segundoApellido'] ?? null,
                ], fn($p) => $p !== null && $p !== '');
                $nombre = implode(' ', $partes) ?: '—';
            }

            $rows[] = [
                '_id'                 => (string) $d['_id'],
                'nombre'              => $nombre,
                'curp'                => (string) ($user['curp']     ?? '—'),
                'rfc'                 => (string) ($user['rfc']      ?? '—'),
                'username'            => (string) ($user['username'] ?? '—'),
                'tipoDeclaracion'     => (string) ($d['tipoDeclaracion'] ?? '—'),
                'anioEjercicio'       => (string) ($d['anioEjercicio']   ?? '—'),
                'firmada'             => (bool) ($d['firmada']             ?? false),
                'declaracionCompleta' => (bool) ($d['declaracionCompleta'] ?? false),
                'createdAt'           => $fecha,
            ];
        }

        return $rows;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function col(string $slug, string $name): Collection
    {
        $inst = $this->discovery->find($slug);
        if (! $inst) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }
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
