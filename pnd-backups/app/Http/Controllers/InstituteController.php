<?php

namespace App\Http\Controllers;

use App\Services\InstanceDiscovery;
use App\Services\InstanceRebuildService;
use App\Services\InstituteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InstituteController extends Controller
{
    public function __construct(
        private InstanceDiscovery $discovery,
        private InstituteService $institute,
        private InstanceRebuildService $rebuild,
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

    public function edit(string $slug)
    {
        $instance = $this->authorizeInstance($slug);
        try {
            $fields = $this->institute->read($slug);
            $error  = null;
        } catch (\Throwable $e) {
            $fields = [
                'ente_publico' => '', 'clave' => '', 'lugar' => '',
                'nombre' => '', 'cargo' => '',
            ];
            $error = $e->getMessage();
        }

        $running = $this->rebuild->isRunning($slug);
        $log     = $this->rebuild->tailLog($slug, 400);

        return view('instances.institute', compact('instance', 'fields', 'error', 'running', 'log'));
    }

    public function update(Request $request, string $slug)
    {
        $this->authorizeInstance($slug);

        $data = $request->validate([
            'ente_publico' => ['required', 'string', 'max:300'],
            'clave'        => ['required', 'string', 'max:60'],
            'lugar'        => ['required', 'string', 'max:200'],
            'nombre'       => ['required', 'string', 'max:200'],
            'cargo'        => ['required', 'string', 'max:200'],
            'rebuild'      => ['nullable'],
        ]);

        try {
            $this->institute->write($slug, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'No se pudo guardar: '.$e->getMessage());
        }

        $okMsg = 'Datos de la institución guardados.';

        if ($request->boolean('rebuild')) {
            try {
                $this->rebuild->start($slug);
                $okMsg .= ' Rebuild lanzado — revisá el log abajo.';
            } catch (\Throwable $e) {
                return redirect()->route('instances.institute', $slug)
                    ->with('error', 'Guardado, pero el rebuild falló al iniciar: '.$e->getMessage());
            }
        }

        return redirect()->route('instances.institute', $slug)->with('ok', $okMsg);
    }

    public function rebuild(string $slug)
    {
        $this->authorizeInstance($slug);
        try {
            $this->rebuild->start($slug);
        } catch (\Throwable $e) {
            return redirect()->route('instances.institute', $slug)
                ->with('error', 'No se pudo lanzar el rebuild: '.$e->getMessage());
        }
        return redirect()->route('instances.institute', $slug)
            ->with('ok', 'Rebuild lanzado — seguí el log abajo.');
    }

    /** Endpoint JSON para auto-refresh del log */
    public function log(string $slug)
    {
        $this->authorizeInstance($slug);
        return response()->json([
            'running' => $this->rebuild->isRunning($slug),
            'log'     => $this->rebuild->tailLog($slug, 400),
        ]);
    }
}
