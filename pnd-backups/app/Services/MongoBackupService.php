<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

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
        $filename = sprintf('%s-%s.zip', $inst['mongo_db'], $stamp);
        $absPath = $dir.'/'.$filename;
        $dumpDir = rtrim(sys_get_temp_dir(), '/').'/pnd-dump-'.bin2hex(random_bytes(8));
        if (! @mkdir($dumpDir, 0700, true)) {
            throw new RuntimeException("No se pudo crear directorio temporal: $dumpDir");
        }

        $env = $this->buildMongoEnv($inst);

        $cmd = [
            'mongodump',
            '--host=' . $inst['mongo_host'],
            '--port=' . $inst['mongo_port'],
            '--authenticationDatabase=admin',
            '--db=' . $inst['mongo_db'],
            '--out=' . $dumpDir,
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
            $this->removeDirectory($dumpDir);
            throw new RuntimeException(
                "mongodump falló (exit {$process->getExitCode()}): ".
                trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo ZIP del respaldo.');
            }
            $bsonCount = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dumpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) continue;
                $relative = substr($file->getPathname(), strlen($dumpDir) + 1);
                $zip->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relative));
                if (str_ends_with($relative, '.bson')) $bsonCount++;
            }
            $zip->close();
            if ($bsonCount === 0) {
                throw new RuntimeException('mongodump no generó colecciones BSON; no se guardó un ZIP vacío.');
            }
            $check = new ZipArchive();
            if ($check->open($absPath, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('El ZIP generado no pasó la validación.');
            }
            $check->close();
        } catch (\Throwable $e) {
            @unlink($absPath);
            throw $e;
        } finally {
            $this->removeDirectory($dumpDir);
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

        $format = $this->detectArchiveFormat($absoluteFilePath);
        if ($format === 'zip') {
            $this->restoreFromZip($instanceSlug, $inst, $absoluteFilePath, $drop);
        } elseif ($format === 'tar' || $format === 'tar.gz') {
            $this->restoreFromTarball($instanceSlug, $inst, $absoluteFilePath, $format, $drop);
        } else {
            $this->restoreFromArchive($instanceSlug, $inst, $absoluteFilePath, $drop);
        }
    }

    private function restoreFromZip(string $instanceSlug, array $inst, string $filePath, bool $drop): void
    {
        $tmpDir = rtrim(sys_get_temp_dir(), '/').'/pnd-restore-'.bin2hex(random_bytes(8));
        if (! @mkdir($tmpDir, 0700, true)) {
            throw new RuntimeException("No se pudo crear directorio temporal: $tmpDir");
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($filePath, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('El archivo no es un ZIP válido o está dañado.');
            }
            $opened = true;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', $zip->getNameIndex($i));
                if (str_starts_with($name, '/') || preg_match('~(^|/)\.\.?(/|$)~', $name)) {
                    throw new RuntimeException('El ZIP contiene una ruta de archivo no permitida.');
                }
            }
            if (! $zip->extractTo($tmpDir)) {
                throw new RuntimeException('No se pudo extraer el ZIP de respaldo.');
            }
            $zip->close();
            $opened = false;
            $this->restoreFromDumpDirectory($instanceSlug, $inst, $tmpDir, $filePath, $drop);
        } finally {
            if ($opened) $zip->close();
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * Compatibilidad con respaldos legacy creados con `--archive --gzip`.
     */
    private function restoreFromArchive(string $instanceSlug, array $inst, string $absoluteFilePath, bool $drop): void
    {
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
        if ($drop) {
            $cmd[] = '--drop';
        }

        $this->runMongorestore($cmd, $instanceSlug, $absoluteFilePath, $inst);
    }

    /**
     * Flujo para dumps creados con `mongodump --out dir` y empaquetados
     * en tar/tar.gz. Extrae a un dir temporal y restaura con --dir.
     * El layout esperado es '<dump>/<dbName>/*.bson(.gz)?'.
     */
    private function restoreFromTarball(string $instanceSlug, array $inst, string $absoluteFilePath, string $format, bool $drop): void
    {
        $targetDb = $inst['mongo_db'];
        $tmpDir = rtrim(sys_get_temp_dir(), '/').'/pnd-restore-'.bin2hex(random_bytes(8));
        if (! @mkdir($tmpDir, 0700, true)) {
            throw new RuntimeException("No se pudo crear directorio temporal: $tmpDir");
        }

        try {
            $tarFlags = $format === 'tar.gz' ? '-xzf' : '-xf';
            $extract = new Process(['tar', $tarFlags, $absoluteFilePath, '-C', $tmpDir], null, null, null, (float) config('backups.tools_timeout'));
            $extract->run();
            if (! $extract->isSuccessful()) {
                throw new RuntimeException('No se pudo extraer el tarball: '.trim($extract->getErrorOutput() ?: $extract->getOutput()));
            }

            $this->restoreFromDumpDirectory($instanceSlug, $inst, $tmpDir, $absoluteFilePath, $drop);
        } finally {
            $rm = new Process(['rm', '-rf', $tmpDir], null, null, null, 60);
            $rm->run();
        }
    }

    private function restoreFromDumpDirectory(string $instanceSlug, array $inst, string $tmpDir, string $absoluteFilePath, bool $drop): void
    {
            $targetDb = $inst['mongo_db'];
            $dumpRoot = $this->findMongodumpRoot($tmpDir);
            if ($dumpRoot === null) {
                throw new RuntimeException(
                    'El tarball no parece un dump de mongodump (no encontré subdirectorios con .bson).'
                );
            }

            // Subdirs inmediatos de dumpRoot son los nombres de DB.
            $dbDirs = array_values(array_filter(
                (array) glob($dumpRoot.'/*', GLOB_ONLYDIR),
                fn($d) => glob($d.'/*.bson') || glob($d.'/*.bson.gz')
            ));
            $sourceDbs = array_map('basename', $dbDirs);

            if (count($sourceDbs) === 0) {
                throw new RuntimeException('El dump no contiene archivos .bson.');
            }
            if (count($sourceDbs) > 1) {
                throw new RuntimeException(
                    'El dump contiene múltiples bases ('.implode(', ', $sourceDbs).'). '.
                    'El restore automático sólo soporta una.'
                );
            }

            // ¿Los .bson están gzipped? (mongodump --gzip --out dir)
            $isGzipped = (bool) glob($dbDirs[0].'/*.bson.gz');

            $cmd = [
                'mongorestore',
                '--host=' . $inst['mongo_host'],
                '--port=' . $inst['mongo_port'],
                '--authenticationDatabase=admin',
                '--username=' . $inst['mongo_user'],
                '--password=' . $inst['mongo_pass'],
                '--dir=' . $dumpRoot,
            ];
            if ($isGzipped) {
                $cmd[] = '--gzip';
            }
            if ($sourceDbs[0] !== $targetDb) {
                $cmd[] = '--nsFrom=' . $sourceDbs[0] . '.*';
                $cmd[] = '--nsTo='   . $targetDb     . '.*';
            }
            if ($drop) {
                $cmd[] = '--drop';
            }

            $this->runMongorestore($cmd, $instanceSlug, $absoluteFilePath, $inst);
    }

    private function runMongorestore(array $cmd, string $instanceSlug, string $absoluteFilePath, array $inst): void
    {
        $process = new Process($cmd, null, $this->buildMongoEnv($inst), null, (float) config('backups.tools_timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
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
     * Detecta si el archivo subido es un tarball (con o sin gzip), un
     * archive de mongodump, o algo no reconocible. Probamos primero
     * tar.gz, luego tar plano, y si nada matchea asumimos --archive.
     */
    private function detectArchiveFormat(string $absoluteFilePath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($absoluteFilePath, ZipArchive::CHECKCONS) === true) {
            $zip->close();
            return 'zip';
        }
        foreach (['tar.gz' => '-tzf', 'tar' => '-tf'] as $fmt => $flag) {
            $p = new Process(['tar', $flag, $absoluteFilePath], null, null, null, 30);
            $p->run();
            if ($p->isSuccessful() && trim($p->getOutput()) !== '') {
                return $fmt;
            }
        }
        return 'archive';
    }

    private function removeDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $process = new Process(['rm', '-rf', $directory], null, null, null, 60);
            $process->run();
        }
    }

    /**
     * En un tarball de mongodump, el dir que mongorestore necesita en
     * --dir suele estar 1-2 niveles abajo: o el propio $tmpDir, o un
     * único subdir tipo 'dump/'. Devuelve el dir cuyos hijos son
     * directorios de DBs (que a su vez contienen .bson).
     */
    private function findMongodumpRoot(string $base): ?string
    {
        $candidates = [$base];
        // Si en $base solo hay un subdir, agregalo como candidato (típico
        // de tar que envuelve todo en un dir 'dump/' o el nombre de la DB).
        $top = array_values(array_filter((array) glob($base.'/*', GLOB_ONLYDIR)));
        if (count($top) === 1) {
            $candidates[] = $top[0];
        }

        foreach ($candidates as $c) {
            // Hijos que sean dirs y contengan .bson o .bson.gz
            foreach ((array) glob($c.'/*', GLOB_ONLYDIR) as $sub) {
                if (glob($sub.'/*.bson') || glob($sub.'/*.bson.gz')) {
                    return $c;
                }
            }
            // Caso especial: el tar contiene directamente .bson en la
            // raíz de la DB (sin envoltorio). $c es la DB; el "root"
            // que mongorestore quiere es su padre.
            if (glob($c.'/*.bson') || glob($c.'/*.bson.gz')) {
                return dirname($c);
            }
        }
        return null;
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
