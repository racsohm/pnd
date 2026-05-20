<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Lanza `docker compose up --build -d app` para una instancia y deja el
 * output en .pnd-rebuild.log dentro del directorio de la instancia.
 *
 * El proceso corre en background (POSIX fork + setsid via shell) para que
 * la request HTTP no bloquee 5+ minutos. El log + un .pid permiten consultar
 * estado desde la UI.
 *
 * Requiere:
 *  - docker socket montado dentro del contenedor pnd-backups
 *  - mount rw de /host/instances
 *  - docker-cli + docker compose plugin instalados (Dockerfile)
 */
class InstanceRebuildService
{
    private const LOG_NAME        = '.pnd-rebuild.log';
    private const PID_NAME        = '.pnd-rebuild.pid';
    private const LOG_WEBAPP_NAME = '.pnd-rebuild-webapp.log';
    private const PID_WEBAPP_NAME = '.pnd-rebuild-webapp.pid';

    public function __construct(private InstanceDiscovery $discovery) {}

    public function instanceDir(string $slug): string
    {
        return rtrim((string) config('backups.instances_path'), '/').'/'.$slug;
    }

    public function logPath(string $slug): string
    {
        return $this->instanceDir($slug).'/'.self::LOG_NAME;
    }

    public function pidPath(string $slug): string
    {
        return $this->instanceDir($slug).'/'.self::PID_NAME;
    }

    /** Lanza rebuild si no hay otro corriendo. Devuelve PID. */
    public function start(string $slug): int
    {
        if (! $this->discovery->find($slug)) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }

        if ($this->isRunning($slug)) {
            throw new RuntimeException('Ya hay un rebuild en curso para esta instancia.');
        }

        $dir = $this->instanceDir($slug);
        $log = $this->logPath($slug);
        $pid = $this->pidPath($slug);

        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException(
                "Directorio no escribible: $dir. ¿Mount /host/instances en rw?"
            );
        }
        if (! file_exists("$dir/docker-compose.yml")) {
            throw new RuntimeException("Falta $dir/docker-compose.yml — instancia no configurada por asistente.sh.");
        }
        if (! file_exists('/var/run/docker.sock')) {
            throw new RuntimeException('No hay /var/run/docker.sock dentro del panel. Revisá docker-compose.yml del panel.');
        }

        // Header del log + truncar.
        $stamp = date('Y-m-d H:i:s');
        @file_put_contents($log, "=== rebuild $slug iniciado $stamp ===\n");

        // Lanzamos en background usando setsid + nohup. El subshell se desacopla
        // de PHP-FPM y sigue corriendo aunque la request termine.
        // Escribimos el PID del comando docker compose (no del shell) usando $!.
        $cmd = sprintf(
            '( cd %s && docker compose -f docker-compose.yml up --build -d app >> %s 2>&1 ; '.
            'rc=$? ; echo "" >> %s ; echo "=== exit $rc ===" >> %s ; rm -f %s ) >/dev/null 2>&1 &',
            escapeshellarg($dir),
            escapeshellarg($log),
            escapeshellarg($log),
            escapeshellarg($log),
            escapeshellarg($pid)
        );

        // Wrap con setsid para detach total y capturar el PID hijo.
        $launch = sprintf('setsid bash -c %s & echo $!', escapeshellarg($cmd));

        $process = Process::fromShellCommandline($launch);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'No se pudo lanzar el rebuild: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $childPid = (int) trim($process->getOutput());
        if ($childPid <= 0) {
            throw new RuntimeException('Rebuild lanzado pero no se obtuvo PID.');
        }
        @file_put_contents($pid, (string) $childPid);
        return $childPid;
    }

    /** ¿Hay un rebuild corriendo para este slug? */
    public function isRunning(string $slug): bool
    {
        $pidFile = $this->pidPath($slug);
        if (! is_file($pidFile)) return false;
        $pid = (int) trim((string) @file_get_contents($pidFile));
        if ($pid <= 0) return false;

        // POSIX kill 0 verifica existencia del proceso.
        if (function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) return true;
            @unlink($pidFile);
            return false;
        }

        // Fallback: /proc/<pid> (Linux).
        if (is_dir("/proc/$pid")) return true;
        @unlink($pidFile);
        return false;
    }

    /** Lee las últimas N líneas del log. */
    public function tailLog(string $slug, int $lines = 200): string
    {
        return $this->readTail($this->logPath($slug), $lines);
    }

    // ── Webapp (frontend) rebuild ─────────────────────────────────────────────

    public function logPathWebapp(string $slug): string
    {
        return $this->instanceDir($slug).'/'.self::LOG_WEBAPP_NAME;
    }

    public function pidPathWebapp(string $slug): string
    {
        return $this->instanceDir($slug).'/'.self::PID_WEBAPP_NAME;
    }

    /** Lanza rebuild del frontend (--no-cache) si no hay otro corriendo. Devuelve PID. */
    public function startWebapp(string $slug): int
    {
        if (! $this->discovery->find($slug)) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }

        if ($this->isRunningWebapp($slug)) {
            throw new RuntimeException('Ya hay un rebuild del frontend en curso para esta instancia.');
        }

        $dir = $this->instanceDir($slug);
        $log = $this->logPathWebapp($slug);
        $pid = $this->pidPathWebapp($slug);

        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException(
                "Directorio no escribible: $dir. ¿Mount /host/instances en rw?"
            );
        }
        if (! file_exists("$dir/docker-compose.yml")) {
            throw new RuntimeException("Falta $dir/docker-compose.yml — instancia no configurada por asistente.sh.");
        }
        if (! file_exists('/var/run/docker.sock')) {
            throw new RuntimeException('No hay /var/run/docker.sock dentro del panel. Revisá docker-compose.yml del panel.');
        }

        $stamp = date('Y-m-d H:i:s');
        @file_put_contents($log, "=== rebuild frontend $slug iniciado $stamp ===\n");

        // build --no-cache y luego up -d; si el build falla, up no se ejecuta.
        $cmd = sprintf(
            '( cd %s && docker compose -f docker-compose.yml build --no-cache webapp >> %s 2>&1 && '.
            'docker compose -f docker-compose.yml up -d webapp >> %s 2>&1 ; '.
            'rc=$? ; echo "" >> %s ; echo "=== exit $rc ===" >> %s ; rm -f %s ) >/dev/null 2>&1 &',
            escapeshellarg($dir),
            escapeshellarg($log),
            escapeshellarg($log),
            escapeshellarg($log),
            escapeshellarg($log),
            escapeshellarg($pid)
        );

        $launch = sprintf('setsid bash -c %s & echo $!', escapeshellarg($cmd));

        $process = Process::fromShellCommandline($launch);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'No se pudo lanzar el rebuild: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $childPid = (int) trim($process->getOutput());
        if ($childPid <= 0) {
            throw new RuntimeException('Rebuild lanzado pero no se obtuvo PID.');
        }
        @file_put_contents($pid, (string) $childPid);
        return $childPid;
    }

    public function isRunningWebapp(string $slug): bool
    {
        $pidFile = $this->pidPathWebapp($slug);
        if (! is_file($pidFile)) return false;
        $pid = (int) trim((string) @file_get_contents($pidFile));
        if ($pid <= 0) return false;

        if (function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) return true;
            @unlink($pidFile);
            return false;
        }

        if (is_dir("/proc/$pid")) return true;
        @unlink($pidFile);
        return false;
    }

    public function tailLogWebapp(string $slug, int $lines = 200): string
    {
        return $this->readTail($this->logPathWebapp($slug), $lines);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function readTail(string $file, int $lines): string
    {
        if (! is_file($file)) return '';

        $raw = (string) @file_get_contents($file);
        if ($raw === '') return '';

        $arr = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        if (count($arr) <= $lines) return $raw;
        return implode("\n", array_slice($arr, -$lines));
    }
}
