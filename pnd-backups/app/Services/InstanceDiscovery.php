<?php

namespace App\Services;

/**
 * Auto-discovery de instancias PND leyendo
 *   <instances_path>/<slug>/SistemaDeclaraciones_backend/.env
 *
 * Devuelve un array de DTOs con las credenciales Mongo. NO persiste —
 * el panel sigue siendo "fuente única" del filesystem para la fase 1.
 */
class InstanceDiscovery
{
    public function all(): array
    {
        return $this->scan()['instances'];
    }

    /**
     * Devuelve diagnóstico completo del discovery: instancias válidas y
     * razones de rechazo para cada subdirectorio descartado. Pensado
     * para mostrar al admin cuando el dashboard sale vacío.
     */
    public function diagnose(): array
    {
        return $this->scan();
    }

    private function scan(): array
    {
        $base = (string) config('backups.instances_path');
        $sub  = (string) config('backups.instance_env_subpath');

        $diag = [
            'base_path'  => $base,
            'base_exists'=> is_dir($base),
            'base_readable' => is_dir($base) && is_readable($base),
            'subdirs'    => 0,
            'rejected'   => [],   // [slug => motivo]
            'instances'  => [],
        ];

        if (! $diag['base_exists'] || ! $diag['base_readable']) {
            return $diag;
        }

        $required = ['MONGO_HOSTNAME', 'MONGO_USERNAME', 'MONGO_PASSWORD', 'MONGO_DB'];
        $instances = [];

        foreach ((array) glob($base.'/*', GLOB_ONLYDIR) as $dir) {
            $diag['subdirs']++;
            $slug = basename($dir);
            $envFile = $dir.'/'.$sub;

            if (! is_file($envFile)) {
                $diag['rejected'][$slug] = "falta {$sub}";
                continue;
            }
            if (! is_readable($envFile)) {
                $diag['rejected'][$slug] = "{$sub} no legible (permisos)";
                continue;
            }

            $env = $this->parseEnv($envFile);
            $missing = array_filter($required, fn($k) => ! isset($env[$k]) || $env[$k] === '');
            if ($missing) {
                $diag['rejected'][$slug] = 'faltan claves en .env: '.implode(', ', $missing);
                continue;
            }

            $instances[$slug] = [
                'slug'          => $slug,
                'name'          => $slug,
                'mongo_host'    => $env['MONGO_HOSTNAME'],
                'mongo_port'    => (int) ($env['MONGO_PORT'] ?? 27017),
                'mongo_user'    => $env['MONGO_USERNAME'],
                'mongo_pass'    => $env['MONGO_PASSWORD'],
                'mongo_db'      => $env['MONGO_DB'],
                'public_url'    => $env['SERVER_PUBLIC_URL'] ?? $env['PAGE_URL'] ?? null,
            ];
        }

        ksort($instances);
        $diag['instances'] = array_values($instances);
        return $diag;
    }

    public function find(string $slug): ?array
    {
        foreach ($this->all() as $i) {
            if ($i['slug'] === $slug) return $i;
        }
        return null;
    }

    private function parseEnv(string $file): array
    {
        $out = [];
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (! str_contains($line, '=')) continue;

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);

            // Quitar comillas circundantes si las hay
            if (strlen($v) >= 2) {
                $first = $v[0]; $last = substr($v, -1);
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $v = substr($v, 1, -1);
                }
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
