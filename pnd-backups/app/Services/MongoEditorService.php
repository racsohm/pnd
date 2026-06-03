<?php

namespace App\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use RuntimeException;

/**
 * Operaciones de escritura sobre las colecciones de una instancia PND.
 *
 *   - users.password (reset)
 *   - users.username / nombre / apellidos / curp / rfc / institucion
 *   - users.roles
 *   - declaraciones.createdAt / updatedAt / anioEjercicio
 *   - declaraciones.deleteOne (+ pull del array users.declaraciones)
 *
 * Reusa InstanceDiscovery para resolver credenciales Mongo desde el
 * .env de la instancia, igual que MongoQueryService.
 */
class MongoEditorService
{
    public const ROLES = ['USER', 'ADMIN', 'SUPER_ADMIN', 'ROOT'];

    public function __construct(private InstanceDiscovery $discovery) {}

    // ── Users ────────────────────────────────────────────────────

    public function getUser(string $slug, string $userId): ?array
    {
        $doc = $this->users($slug)->findOne(['_id' => $this->oid($userId)]);
        if (! $doc) return null;
        return json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * Actualiza datos personales. Aplica las mismas reglas del schema
     * Mongoose: nombre/apellidos/curp/rfc → UPPERCASE, username → lowercase
     * y trim. Solo escribe las claves no-null del array.
     */
    public function updateUser(string $slug, string $userId, array $fields): void
    {
        $set = [];
        foreach (['nombre', 'primerApellido', 'segundoApellido', 'curp', 'rfc'] as $k) {
            if (array_key_exists($k, $fields) && $fields[$k] !== null) {
                $set[$k] = mb_strtoupper((string) $fields[$k]);
            }
        }
        if (array_key_exists('username', $fields) && $fields['username'] !== null) {
            $set['username'] = mb_strtolower(trim((string) $fields['username']));
        }
        if (array_key_exists('institucion_clave', $fields) && $fields['institucion_clave'] !== null) {
            $set['institucion.clave'] = (string) $fields['institucion_clave'];
        }
        if (array_key_exists('institucion_valor', $fields) && $fields['institucion_valor'] !== null) {
            $set['institucion.valor'] = (string) $fields['institucion_valor'];
        }

        if (! $set) return;

        $set['updatedAt'] = new UTCDateTime();

        $result = $this->users($slug)->updateOne(
            ['_id' => $this->oid($userId)],
            ['$set' => $set],
        );
        if ($result->getMatchedCount() === 0) {
            throw new RuntimeException("Usuario $userId no encontrado.");
        }
    }

    public function resetPassword(string $slug, string $userId, string $newPassword): void
    {
        $hash = PndUserHash::hash($newPassword);
        $result = $this->users($slug)->updateOne(
            ['_id' => $this->oid($userId)],
            ['$set' => [
                'password'   => $hash,
                'resetToken' => (object) [],   // invalida cualquier reset pendiente
                'updatedAt'  => new UTCDateTime(),
            ]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new RuntimeException("Usuario $userId no encontrado.");
        }
    }

    /** Reemplaza el array de roles completo. Valida contra el enum del backend. */
    public function setRoles(string $slug, string $userId, array $roles): void
    {
        $roles = array_values(array_unique(array_filter($roles, fn($r) => in_array($r, self::ROLES, true))));
        if (! $roles) {
            throw new RuntimeException('Debe asignarse al menos un rol válido.');
        }
        $result = $this->users($slug)->updateOne(
            ['_id' => $this->oid($userId)],
            ['$set' => ['roles' => $roles, 'updatedAt' => new UTCDateTime()]],
        );
        if ($result->getMatchedCount() === 0) {
            throw new RuntimeException("Usuario $userId no encontrado.");
        }
    }

    // ── Declaraciones ────────────────────────────────────────────

    public function getDeclaracion(string $slug, string $declId): ?array
    {
        $doc = $this->declaraciones($slug)->findOne(
            ['_id' => $this->oid($declId)],
            ['projection' => ['createdAt' => 1, 'anioEjercicio' => 1, 'tipoDeclaracion' => 1, 'firmada' => 1]],
        );
        if (! $doc) return null;
        return json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
    }

    /** Lista declaraciones que pertenecen al usuario, más recientes primero. */
    public function listDeclaracionesForUser(string $slug, string $userId): array
    {
        $cursor = $this->declaraciones($slug)->find(
            ['owner' => $this->oid($userId)],
            [
                'sort'       => ['_id' => -1],
                'projection' => [
                    'tipoDeclaracion' => 1,
                    'anioEjercicio'   => 1,
                    'firmada'         => 1,
                    'declaracionCompleta' => 1,
                    'createdAt'       => 1,
                    'updatedAt'       => 1,
                    'owner'           => 1,
                ],
            ],
        );
        return array_map(
            fn($d) => json_decode(json_encode($d, JSON_UNESCAPED_UNICODE), true),
            $cursor->toArray(),
        );
    }

    /**
     * Cambia createdAt (y opcionalmente anioEjercicio). updatedAt se sella
     * a "ahora" porque es la única fecha que el backend recalcula al guardar.
     */
    public function updateDeclaracionFecha(
        string $slug,
        string $declId,
        \DateTimeInterface $fecha,
        ?int $anioEjercicio = null,
    ): void {
        $set = [
            'createdAt' => new UTCDateTime($fecha),
            'updatedAt' => new UTCDateTime(),
        ];
        if ($anioEjercicio !== null) {
            $set['anioEjercicio'] = $anioEjercicio;
        }
        $result = $this->declaraciones($slug)->updateOne(
            ['_id' => $this->oid($declId)],
            ['$set' => $set],
        );
        if ($result->getMatchedCount() === 0) {
            throw new RuntimeException("Declaración $declId no encontrada.");
        }
    }

    /**
     * Borra la declaración Y la quita del array users.declaraciones del owner.
     * Sin esto último el usuario sigue "viendo" la decl en su panel hasta el
     * próximo populate fallido.
     */
    public function deleteDeclaracion(string $slug, string $declId): void
    {
        $oid = $this->oid($declId);
        $doc = $this->declaraciones($slug)->findOne(['_id' => $oid], ['projection' => ['owner' => 1]]);
        if (! $doc) {
            throw new RuntimeException("Declaración $declId no encontrada.");
        }

        $this->declaraciones($slug)->deleteOne(['_id' => $oid]);

        $owner = $doc['owner'] ?? null;
        if ($owner instanceof ObjectId) {
            $this->users($slug)->updateOne(
                ['_id' => $owner],
                ['$pull' => ['declaraciones' => $oid], '$set' => ['updatedAt' => new UTCDateTime()]],
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function oid(string $id): ObjectId
    {
        if (! preg_match('/^[a-f0-9]{24}$/i', $id)) {
            throw new RuntimeException("ID inválido: $id");
        }
        return new ObjectId($id);
    }

    private function users(string $slug): Collection
    {
        return $this->collection($slug, 'users');
    }

    private function declaraciones(string $slug): Collection
    {
        return $this->collection($slug, 'declaraciones');
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
}
