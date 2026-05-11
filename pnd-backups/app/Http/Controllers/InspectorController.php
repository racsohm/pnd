<?php

namespace App\Http\Controllers;

use App\Services\InstanceDiscovery;
use App\Services\MongoQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InspectorController extends Controller
{
    /** Colecciones que el panel expone. Si crece, se mueve a config. */
    private const COLLECTIONS = ['users', 'declaraciones'];

    private const PAGE_SIZE = 50;

    public function __construct(
        private InstanceDiscovery $discovery,
        private MongoQueryService $query,
    ) {}

    public function show(Request $request, string $slug)
    {
        $instance = $this->discovery->find($slug);
        if (! $instance) {
            throw new NotFoundHttpException("Instancia '$slug' no encontrada.");
        }
        if (! Auth::user()->canSeeInstance($slug)) {
            abort(403);
        }

        $collection = (string) $request->query('collection', self::COLLECTIONS[0]);
        if (! in_array($collection, self::COLLECTIONS, true)) {
            $collection = self::COLLECTIONS[0];
        }
        $q = trim((string) $request->query('q', ''));

        $totals = [];
        $docs   = [];
        $error  = null;

        try {
            foreach (self::COLLECTIONS as $name) {
                $totals[$name] = $this->query->count($slug, $name);
            }
            $docs = $q !== ''
                ? $this->query->search($slug, $collection, $q, self::PAGE_SIZE)
                : $this->query->latest($slug, $collection, self::PAGE_SIZE);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('instances.inspect', [
            'instance'    => $instance,
            'collection'  => $collection,
            'collections' => self::COLLECTIONS,
            'q'           => $q,
            'docs'        => $docs,
            'totals'      => $totals,
            'pageSize'    => self::PAGE_SIZE,
            'error'       => $error,
        ]);
    }
}
