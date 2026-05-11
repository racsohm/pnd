<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\InstanceDiscovery;
use App\Services\MongoQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InstanceController extends Controller
{
    private const TRACKED = ['users', 'declaraciones'];

    public function show(Request $request, InstanceDiscovery $discovery, MongoQueryService $query, string $slug)
    {
        $instance = $discovery->find($slug);
        if (! $instance) {
            throw new NotFoundHttpException("Instancia '$slug' no encontrada.");
        }

        $user = Auth::user();
        if (! $user->canSeeInstance($slug)) {
            abort(403);
        }

        $backups = Backup::where('instance_slug', $slug)
            ->orderByDesc('created_at')
            ->get();

        [$stats, $statsError] = $this->loadStats($slug, $query);

        return view('instances.show', compact('instance', 'backups', 'stats', 'statsError'));
    }

    /**
     * Calcula totales + nuevos en 7d/30d + últimos 5 para cada colección
     * trackeada. Devuelve [stats, error] — si Mongo no contesta, error
     * trae el mensaje y la vista degrada con gracia.
     */
    private function loadStats(string $slug, MongoQueryService $query): array
    {
        try {
            $now      = new \DateTimeImmutable();
            $week     = $now->modify('-7 days')->getTimestamp();
            $month    = $now->modify('-30 days')->getTimestamp();

            $stats = [];
            foreach (self::TRACKED as $name) {
                $stats[$name] = [
                    'total'   => $query->count($slug, $name),
                    'last_7'  => $query->countSince($slug, $name, $week),
                    'last_30' => $query->countSince($slug, $name, $month),
                    'latest'  => $query->latest($slug, $name, 5),
                ];
            }
            return [$stats, null];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }
}
