<?php

namespace App\Http\Controllers;

use App\Services\InstanceDiscovery;
use App\Services\InstanceRebuildService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FrontendRebuildController extends Controller
{
    public function __construct(
        private InstanceDiscovery $discovery,
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

    public function rebuild(string $slug)
    {
        $this->authorizeInstance($slug);
        try {
            $this->rebuild->startWebapp($slug);
        } catch (\Throwable $e) {
            return redirect()->route('instances.show', $slug)
                ->with('error', 'No se pudo lanzar el rebuild del frontend: '.$e->getMessage());
        }
        return redirect()->route('instances.show', $slug)
            ->with('ok', 'Rebuild del frontend lanzado — puede tardar varios minutos. Seguí el log abajo.');
    }

    public function log(string $slug)
    {
        $this->authorizeInstance($slug);
        return response()->json([
            'running' => $this->rebuild->isRunningWebapp($slug),
            'log'     => $this->rebuild->tailLogWebapp($slug, 400),
        ]);
    }
}
