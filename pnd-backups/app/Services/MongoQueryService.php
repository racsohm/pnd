<?php

namespace App\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\Client;
use MongoDB\Collection;
use RuntimeException;

/**
 * Acceso de solo-lectura a las colecciones de una instancia. Se usa
 * desde el Inspector del panel para verificar el contenido tras un
 * restore. Habla a Mongo por el driver oficial (pecl mongodb), no por
 * shell-outs como MongoBackupService.
 */
class MongoQueryService
{
    public function __construct(private InstanceDiscovery $discovery) {}

    public function count(string $slug, string $collection): int
    {
        return $this->collection($slug, $collection)->countDocuments();
    }

    public function latest(string $slug, string $collection, int $limit = 50): array
    {
        $cursor = $this->collection($slug, $collection)->find(
            [],
            ['sort' => ['_id' => -1], 'limit' => $limit]
        );
        return $this->normalize($cursor->toArray());
    }

    public function search(string $slug, string $collection, string $q, int $limit = 50): array
    {
        $cursor = $this->collection($slug, $collection)->find(
            $this->buildSearchFilter($q),
            ['sort' => ['_id' => -1], 'limit' => $limit]
        );
        return $this->normalize($cursor->toArray());
    }

    private function collection(string $slug, string $name): Collection
    {
        $inst = $this->discovery->find($slug);
        if (! $inst) {
            throw new RuntimeException("Instancia '$slug' no encontrada.");
        }
        $uri = sprintf(
            'mongodb://%s:%s@%s:%d/?authSource=admin',
            rawurlencode($inst['mongo_user']),
            rawurlencode($inst['mongo_pass']),
            $inst['mongo_host'],
            (int) $inst['mongo_port'],
        );
        return (new Client($uri))->selectCollection($inst['mongo_db'], $name);
    }

    /**
     * Busca por ObjectId exacto (si el query es 24 hex) o por email /
     * username con regex case-insensitive. El _id también se intenta como
     * string puro por si la app guarda IDs no-ObjectId.
     */
    private function buildSearchFilter(string $q): array
    {
        $filters = [];
        if (preg_match('/^[a-f0-9]{24}$/i', $q)) {
            try {
                $filters[] = ['_id' => new ObjectId($q)];
            } catch (\Throwable) {
                // ignoramos: no era un ObjectId válido aun cumpliendo el regex
            }
        }
        $filters[] = ['_id'      => $q];
        $filters[] = ['email'    => new Regex(preg_quote($q, '/'), 'i')];
        $filters[] = ['username' => new Regex(preg_quote($q, '/'), 'i')];

        return ['$or' => $filters];
    }

    /**
     * Convierte BSONDocument / BSONArray / ObjectId / UTCDateTime a
     * estructuras nativas para que la vista pueda hacer json_encode
     * con JSON_PRETTY_PRINT sin sorpresas.
     */
    private function normalize(array $docs): array
    {
        return array_map(
            fn($d) => json_decode(json_encode($d, JSON_UNESCAPED_UNICODE), true),
            $docs,
        );
    }
}
