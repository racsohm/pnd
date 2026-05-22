<?php

namespace App\Services;

use RuntimeException;

/**
 * Lee/escribe los parámetros SMTP en
 *   <instances_path>/<slug>/SistemaDeclaraciones_backend/.env
 *
 * Solo toca las claves SMTP; preserva todas las demás líneas del archivo.
 */
class MailConfigService
{
    private const MANAGED_KEYS = [
        'USE_SMTP', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE',
        'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL',
        'SENDGRID_API_KEY', 'SENDGRID_MAIL_SENDER',
    ];

    public function __construct(private InstanceDiscovery $discovery) {}

    public function envPath(string $slug): string
    {
        $base = rtrim((string) config('backups.instances_path'), '/');
        $sub  = (string) config('backups.instance_env_subpath');
        return $base.'/'.$slug.'/'.$sub;
    }

    public function read(string $slug): array
    {
        if (! $this->discovery->find($slug)) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }

        $file = $this->envPath($slug);
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException("No se puede leer: $file");
        }

        $env = $this->parseEnv($file);

        return [
            'use_smtp'             => ($env['USE_SMTP'] ?? 'false') === 'true',
            'smtp_host'            => $env['SMTP_HOST'] ?? '',
            'smtp_port'            => $env['SMTP_PORT'] ?? '587',
            'smtp_secure'          => ($env['SMTP_SECURE'] ?? 'false') === 'true',
            'smtp_user'            => $env['SMTP_USER'] ?? '',
            'smtp_password'        => $env['SMTP_PASSWORD'] ?? '',
            'smtp_from_email'      => $env['SMTP_FROM_EMAIL'] ?? '',
            'sendgrid_api_key'     => $env['SENDGRID_API_KEY'] ?? '',
            'sendgrid_mail_sender' => $env['SENDGRID_MAIL_SENDER'] ?? '',
        ];
    }

    public function write(string $slug, array $fields): void
    {
        if (! $this->discovery->find($slug)) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }

        $file = $this->envPath($slug);
        if (! is_file($file)) {
            throw new RuntimeException("Archivo .env no encontrado: $file");
        }
        if (! is_writable($file)) {
            throw new RuntimeException("Sin permisos de escritura en: $file");
        }

        $map = [
            'USE_SMTP'             => $fields['use_smtp'] ? 'true' : 'false',
            'SMTP_HOST'            => $fields['smtp_host'],
            'SMTP_PORT'            => (string) $fields['smtp_port'],
            'SMTP_SECURE'          => $fields['smtp_secure'] ? 'true' : 'false',
            'SMTP_USER'            => $fields['smtp_user'],
            'SMTP_PASSWORD'        => $fields['smtp_password'],
            'SMTP_FROM_EMAIL'      => $fields['smtp_from_email'],
            'SENDGRID_API_KEY'     => $fields['sendgrid_api_key'],
            'SENDGRID_MAIL_SENDER' => $fields['sendgrid_mail_sender'],
        ];

        $lines   = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $written = array_fill_keys(array_keys($map), false);

        foreach ($lines as &$line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k] = explode('=', $line, 2);
            $k = trim($k);
            if (array_key_exists($k, $map)) {
                $line = $k.'='.$this->quoteValue($map[$k]);
                $written[$k] = true;
            }
        }
        unset($line);

        foreach ($map as $k => $v) {
            if (! $written[$k]) {
                $lines[] = $k.'='.$this->quoteValue($v);
            }
        }

        $content = implode("\n", $lines);
        if (! str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        $tmp = $file.'.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("No se pudo escribir en: $tmp");
        }
        if (! rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("No se pudo renombrar $tmp → $file");
        }
    }

    private function parseEnv(string $file): array
    {
        $out   = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2) {
                $first = $v[0];
                $last  = substr($v, -1);
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $v = substr($v, 1, -1);
                }
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function quoteValue(string $v): string
    {
        if ($v === '' || preg_match('/[\s#"\'\\\\]/', $v)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"';
        }
        return $v;
    }
}
