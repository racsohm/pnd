<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\InstanceDiscovery;
use App\Services\MongoBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BackupController extends Controller
{
    public function __construct(
        private InstanceDiscovery $discovery,
        private MongoBackupService $mongo,
    ) {}

    private function authorizeInstance(string $slug): array
    {
        $inst = $this->discovery->find($slug);
        if (! $inst) {
            throw new NotFoundHttpException("Instancia '$slug' no encontrada.");
        }
        if (! Auth::user()->canSeeInstance($slug)) {
            abort(403);
        }
        return $inst;
    }

    /** Crea un dump nuevo (mongodump) */
    public function store(string $slug)
    {
        $this->authorizeInstance($slug);

        try {
            $info = $this->mongo->dump($slug);
        } catch (\Throwable $e) {
            return back()->with('error', 'Falló el respaldo: '.$e->getMessage());
        }

        Backup::create([
            'instance_slug' => $slug,
            'filename'      => $info['filename'],
            'size_bytes'    => $info['size_bytes'],
            'source'        => 'dump',
            'created_by'    => Auth::id(),
        ]);

        return redirect()->route('instances.show', $slug)
            ->with('ok', "Respaldo creado: {$info['filename']}");
    }

    /** Descarga un .gz */
    public function download(int $id)
    {
        $backup = Backup::findOrFail($id);
        if (! Auth::user()->canSeeInstance($backup->instance_slug)) abort(403);
        if (! $backup->exists()) {
            return back()->with('error', 'El archivo ya no está en disco.');
        }
        return response()->download($backup->fullPath(), $backup->filename, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    /** Sube un .gz desde el navegador */
    public function upload(Request $request, string $slug)
    {
        $this->authorizeInstance($slug);

        $maxKb = ((int) config('backups.upload_max_mb', 512)) * 1024;
        $request->validate([
            'archive' => ['required', 'file', "max:$maxKb"],
            'notes'   => ['nullable', 'string', 'max:500'],
        ], [
            'archive.max' => "El archivo excede el máximo permitido ({$maxKb} KB).",
        ]);

        $file = $request->file('archive');
        $orig = $file->getClientOriginalName();
        // Sanitiza: solo letras, números, guiones, puntos
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($orig, PATHINFO_BASENAME));
        if (! Str::endsWith($base, '.gz')) {
            $base .= '.gz';
        }
        $stamp = date('Ymd-His');
        $finalName = "upload-{$stamp}-{$base}";

        $dir = $this->mongo->ensureBackupsDir($slug);
        $file->move($dir, $finalName);

        $absPath = $dir.'/'.$finalName;
        Backup::create([
            'instance_slug' => $slug,
            'filename'      => $finalName,
            'size_bytes'    => is_file($absPath) ? filesize($absPath) : 0,
            'source'        => 'upload',
            'created_by'    => Auth::id(),
            'notes'         => $request->input('notes'),
        ]);

        return redirect()->route('instances.show', $slug)
            ->with('ok', "Respaldo subido: $finalName");
    }

    /** Restaura un respaldo */
    public function restore(Request $request, int $id)
    {
        $backup = Backup::findOrFail($id);
        if (! Auth::user()->canSeeInstance($backup->instance_slug)) abort(403);
        if (! $backup->exists()) {
            return back()->with('error', 'El archivo no está en disco.');
        }

        $data = $request->validate([
            'drop'    => ['nullable'],
            'confirm' => ['required', 'string', 'in:RESTAURAR'],
        ]);

        $drop = $request->boolean('drop', true);

        try {
            $this->mongo->restore($backup->instance_slug, $backup->fullPath(), $drop);
        } catch (\Throwable $e) {
            return back()->with('error', 'Falló la restauración: '.$e->getMessage());
        }

        return redirect()->route('instances.show', $backup->instance_slug)
            ->with('ok', "Restauración completa desde {$backup->filename}".($drop ? ' (con --drop)' : ' (sin --drop)'));
    }

    /** Elimina un respaldo (archivo + registro) */
    public function destroy(int $id)
    {
        $backup = Backup::findOrFail($id);
        if (! Auth::user()->canSeeInstance($backup->instance_slug)) abort(403);

        if ($backup->exists()) {
            @unlink($backup->fullPath());
        }
        $slug = $backup->instance_slug;
        $name = $backup->filename;
        $backup->delete();

        return redirect()->route('instances.show', $slug)
            ->with('ok', "Eliminado: $name");
    }
}
