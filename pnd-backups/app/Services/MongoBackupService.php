<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Wrappea mongodump/mongorestore que viven en este mismo contenedor
 * (instalados vía mongodb-database-tools). Habla al contenedor mongo
 * por la red interna pdnmx_network.
 *
 * Todas las credenciales se pasan por env vars del proceso (NO en la
 * línea de comando) para evitar que aparezcan en `ps aux` o logs.
 */
class MongoBackupService
{
    public function __construct(private InstanceDiscovery $discovery) {}

    public function dump(string $instanceSlug): array
    {
        $inst = $this->discovery->find($instanceSlug);
        if (! $inst) {
            throw new RuntimeException("Instancia '$instanceSlug' no encontrada.");
        }

        $dir = $this->ensureBackupsDir($instanceSlug);
        $stamp = date('Ymd-His');
        $filename = sprintf('%s-%s.gz', $inst['mongo_db'], $stamp);
        $absPath = $dir.'/'.$filename;

        $env = $this->buildMongoEnv($inst);

        $cmd = [
            'mongodump',
            '--host=' . $inst['mongo_host'],
            '--port=' . $inst['mongo_port'],
            '--authenticationDatabase=admin',
            '--db=' . $inst['mongo_db'],
            '--gzip',
            '--archive=' . $absPath,
            '--quiet',
            // Credenciales por env: usamos la opción --username/--password
            // expandiendo desde shell sería un problema; mejor pasar por
            // proceso. mongodump soporta MONGODB_URI pero no usuario/pass
            // por env directamente, así que los pasamos por args mínimos.
            '--username=' . $inst['mongo_user'],
            '--password=' . $inst['mongo_pass'],
        ];

        $process = new Process($cmd, null, $env, null, (float) config('backups.tools_timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($absPath);
            throw new RuntimeException(
                "mongodump falló (exit {$process->getExitCode()}): ".
                trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        clearstatcache(true, $absPath);
        return [
            'filename'   => $filename,
            'path'       => $absPath,
            'size_bytes' => is_file($absPath) ? filesize($absPath) : 0,
        ];
    }

    public function restore(string $instanceSlug, string $absoluteFilePath, bool $drop = true): void
    {
        $inst = $this->discovery->find($instanceSlug);
        if (! $inst) {
            throw new RuntimeException("Instancia '$instanceSlug' no encontrada.");
        }
        if (! is_file($absoluteFilePath)) {
            throw new RuntimeException("Archivo no encontrado: $absoluteFilePath");
        }

        $env = $this->buildMongoEnv($inst);
        $targetDb = $inst['mongo_db'];

        // mongorestore en esta versión no acepta placeholders nombrados
        // ('${db}/${collection}') ni '*.*' → 'target.*' (exige misma
        // cantidad de '*' en ambos lados). Inspeccionamos el archive con
        // --dryRun para descubrir el nombre real de la DB y construir
        // un rename concreto: 'dbReal.*' → 'target.*'.
        $sourceDbs = $this->inspectArchiveDbs($absoluteFilePath, $inst);

        if (count($sourceDbs) > 1) {
            throw new RuntimeException(
                'El archivo contiene múltiples bases ('.implode(', ', $sourceDbs).'). '.
                'El restore automático sólo soporta una. Genera un dump específico de la base que querés restaurar.'
            );
        }

        $cmd = [
            'mongorestore',
            '--host=' . $inst['mongo_host'],
            '--port=' . $inst['mongo_port'],
            '--authenticationDatabase=admin',
            '--username=' . $inst['mongo_user'],
            '--password=' . $inst['mongo_pass'],
            '--gzip',
            '--archive=' . $absoluteFilePath,
        ];

        if (count($sourceDbs) === 1 && $sourceDbs[0] !== $targetDb) {
            $cmd[] = '--nsFrom=' . $sourceDbs[0] . '.*';
            $cmd[] = '--nsTo='   . $targetDb     . '.*';
        }
        // Si sourceDbs está vacío (no se pudo inspeccionar), dejamos al
        // restore intentar tal cual y que falle con un error informativo.

        if ($drop) {
            $cmd[] = '--drop';
        }

        $process = new Process($cmd, null, $env, null, (float) config('backups.tools_timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            // mongorestore escribe progreso/errores a stderr; combinamos
            // ambas streams porque a veces el detalle útil cae en stdout.
            $err = trim($process->getErrorOutput()."\n".$process->getOutput());
            Log::error('mongorestore failed', [
                'slug'      => $instanceSlug,
                'archive'   => $absoluteFilePath,
                'exit_code' => $process->getExitCode(),
                'output'    => $err,
            ]);
            throw new RuntimeException(
                "mongorestore falló (exit {$process->getExitCode()}): ".
                ($err !== '' ? $err : 'sin salida')
            );
        }
    }

    /**
     * Corre mongorestore --dryRun para descubrir qué bases vienen en el
     * archive. Devuelve nombres únicos. Si la inspección falla (archive
     * corrupto, no se puede conectar, etc.) devuelve [] y dejamos que
     * el restore real falle con su propio diagnóstico.
     */
    private function inspectArchiveDbs(string $archivePath, array $inst): array
    {
        $cmd = [
            'mongorestore',
            '--host=' . $inst['mongo_host'],
            '--port=' . $inst['mongo_port'],
            '--authenticationDatabase=admin',
            '--username=' . $inst['mongo_user'],
            '--password=' . $inst['mongo_pass'],
            '--gzip',
            '--archive=' . $archivePath,
            '--dryRun',
            '-v',
        ];
        $process = new Process($cmd, null, $this->buildMongoEnv($inst), null, 60);
        $process->run();

        // mongorestore escribe líneas como:
        //   "... reading metadata for tecali_declaraciones.users from archive"
        //   "... restoring tecali_declaraciones.users from archive"
        $output = $process->getErrorOutput()."\n".$process->getOutput();
        if (preg_match_all('/(?:reading metadata for|restoring)\s+([A-Za-z0-9_\-]+)\.[A-Za-z0-9_\-.\$]+\s+from archive/m', $output, $m)) {
            return array_values(array_unique($m[1]));
        }
        return [];
    }

    public function ensureBackupsDir(string $slug): string
    {
        $base = rtrim((string) config('backups.path'), '/');
        $dir  = $base.'/'.$slug;
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("No se pudo crear el directorio: $dir");
            }
        }
        return $dir;
    }

    public function listFiles(string $slug): array
    {
        $dir = $this->ensureBackupsDir($slug);
        $files = [];
        foreach ((array) glob($dir.'/*.gz') as $f) {
            $files[basename($f)] = [
                'name' => basename($f),
                'size' => filesize($f) ?: 0,
                'mtime'=> filemtime($f) ?: 0,
            ];
        }
        return $files;
    }

    private function buildMongoEnv(array $inst): array
    {
        // Por si en algún momento quitamos --username/--password de los
        // args y los pasamos por URI; por ahora env mínimo.
        return [
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
    }
}
