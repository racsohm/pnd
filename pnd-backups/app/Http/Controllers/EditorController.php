<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\InstanceDiscovery;
use App\Services\MongoEditorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CRUD limitado sobre datos operativos: usuarios (password, email,
 * datos personales, rol) y declaraciones (fecha o borrado). NO toca
 * el JSON de institución — eso vive en InstituteController.
 *
 * Toda escritura pasa por MongoEditorService, que aplica las mismas
 * normalizaciones del schema Mongoose (UPPERCASE, lowercase, etc).
 */
class EditorController extends Controller
{
    public function __construct(
        private InstanceDiscovery $discovery,
        private MongoEditorService $editor,
        private AuditService $audit,
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

    // ── Vista de edición de usuario ──────────────────────────────

    public function editUser(string $slug, string $id)
    {
        $instance = $this->authorizeInstance($slug);

        try {
            $user = $this->editor->getUser($slug, $id);
        } catch (\Throwable $e) {
            return redirect()->route('instances.inspect', $slug)
                ->with('error', 'No se pudo leer el usuario: '.$e->getMessage());
        }
        if (! $user) {
            return redirect()->route('instances.inspect', $slug)
                ->with('error', "Usuario $id no existe.");
        }

        try {
            $declaraciones = $this->editor->listDeclaracionesForUser($slug, $id);
        } catch (\Throwable $e) {
            $declaraciones = [];
        }

        return view('instances.user-edit', [
            'instance'      => $instance,
            'user'          => $user,
            'userId'        => $id,
            'declaraciones' => $declaraciones,
            'roles'         => MongoEditorService::ROLES,
        ]);
    }

    // ── Acciones de usuario ──────────────────────────────────────

    public function updateUser(Request $request, string $slug, string $id)
    {
        $this->authorizeInstance($slug);

        $data = $request->validate([
            'username'          => ['nullable', 'string', 'max:200'],
            'nombre'            => ['nullable', 'string', 'max:200'],
            'primerApellido'    => ['nullable', 'string', 'max:200'],
            'segundoApellido'   => ['nullable', 'string', 'max:200'],
            'curp'              => ['nullable', 'string', 'max:30'],
            'rfc'               => ['nullable', 'string', 'max:30'],
            'institucion_clave' => ['nullable', 'string', 'max:60'],
            'institucion_valor' => ['nullable', 'string', 'max:300'],
        ]);

        $beforeUser = null;
        try { $beforeUser = $this->editor->getUser($slug, $id); } catch (\Throwable) {}

        try {
            $this->editor->updateUser($slug, $id, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'No se pudo guardar: '.$e->getMessage());
        }

        $this->audit->log('user.update', [
            'instance_slug' => $slug,
            'target_type'   => 'user',
            'target_id'     => $id,
            'target_name'   => $beforeUser['username'] ?? $beforeUser['email'] ?? null,
            'details'       => $this->userDiff($data, $beforeUser) ?: null,
        ]);

        return redirect()->route('users.edit', ['slug' => $slug, 'id' => $id])
            ->with('ok', 'Datos del usuario actualizados.');
    }

    public function resetPassword(Request $request, string $slug, string $id)
    {
        $this->authorizeInstance($slug);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación no coincide.',
        ]);

        $beforeUser = null;
        try { $beforeUser = $this->editor->getUser($slug, $id); } catch (\Throwable) {}

        try {
            $this->editor->resetPassword($slug, $id, $data['password']);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo cambiar la contraseña: '.$e->getMessage());
        }

        $this->audit->log('user.password_reset', [
            'instance_slug' => $slug,
            'target_type'   => 'user',
            'target_id'     => $id,
            'target_name'   => $beforeUser['username'] ?? $beforeUser['email'] ?? null,
        ]);

        return redirect()->route('users.edit', ['slug' => $slug, 'id' => $id])
            ->with('ok', 'Contraseña actualizada. El usuario puede entrar con la nueva.');
    }

    public function updateRoles(Request $request, string $slug, string $id)
    {
        $this->authorizeInstance($slug);

        $data = $request->validate([
            'roles'   => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'in:'.implode(',', MongoEditorService::ROLES)],
        ]);

        $beforeUser = null;
        try { $beforeUser = $this->editor->getUser($slug, $id); } catch (\Throwable) {}
        $beforeRoles = $beforeUser['roles'] ?? [];

        try {
            $this->editor->setRoles($slug, $id, $data['roles']);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo cambiar el rol: '.$e->getMessage());
        }

        $this->audit->log('user.roles_update', [
            'instance_slug' => $slug,
            'target_type'   => 'user',
            'target_id'     => $id,
            'target_name'   => $beforeUser['username'] ?? $beforeUser['email'] ?? null,
            'details'       => ['before' => $beforeRoles, 'after' => $data['roles']],
        ]);

        return redirect()->route('users.edit', ['slug' => $slug, 'id' => $id])
            ->with('ok', 'Roles actualizados.');
    }

    // ── Acciones de declaración ──────────────────────────────────

    public function updateDeclaracionFecha(Request $request, string $slug, string $id)
    {
        $this->authorizeInstance($slug);

        $data = $request->validate([
            'fecha'         => ['required', 'date'],
            'anioEjercicio' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'return_to'     => ['nullable', 'string'],
        ]);

        $beforeDecl = null;
        try { $beforeDecl = $this->editor->getDeclaracion($slug, $id); } catch (\Throwable) {}

        try {
            $fecha = new \DateTimeImmutable($data['fecha']);
            $this->editor->updateDeclaracionFecha(
                $slug,
                $id,
                $fecha,
                $data['anioEjercicio'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo cambiar la fecha: '.$e->getMessage());
        }

        $this->audit->log('declaracion.fecha_update', [
            'instance_slug' => $slug,
            'target_type'   => 'declaracion',
            'target_id'     => $id,
            'details'       => [
                'before' => [
                    'fecha'         => $this->mongoDateToString($beforeDecl['createdAt'] ?? null),
                    'anioEjercicio' => $beforeDecl['anioEjercicio'] ?? null,
                ],
                'after' => [
                    'fecha'         => $data['fecha'],
                    'anioEjercicio' => $data['anioEjercicio'] ?? null,
                ],
            ],
        ]);

        $target = $data['return_to'] ?? null;
        if ($target && str_starts_with($target, '/')) {
            return redirect($target)->with('ok', 'Fecha de declaración actualizada.');
        }
        return back()->with('ok', 'Fecha de declaración actualizada.');
    }

    public function deleteDeclaracion(Request $request, string $slug, string $id)
    {
        $this->authorizeInstance($slug);

        $data = $request->validate([
            'confirm'   => ['required', 'in:ELIMINAR'],
            'return_to' => ['nullable', 'string'],
        ], [
            'confirm.in' => 'Debes escribir ELIMINAR para confirmar.',
        ]);

        $beforeDecl = null;
        try { $beforeDecl = $this->editor->getDeclaracion($slug, $id); } catch (\Throwable) {}

        try {
            $this->editor->deleteDeclaracion($slug, $id);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo eliminar: '.$e->getMessage());
        }

        $this->audit->log('declaracion.delete', [
            'instance_slug' => $slug,
            'target_type'   => 'declaracion',
            'target_id'     => $id,
            'target_name'   => $beforeDecl
                ? trim(($beforeDecl['tipoDeclaracion'] ?? '?').' '.($beforeDecl['anioEjercicio'] ?? ''))
                : null,
            'details'       => $beforeDecl ? [
                'tipoDeclaracion' => $beforeDecl['tipoDeclaracion'] ?? null,
                'anioEjercicio'   => $beforeDecl['anioEjercicio'] ?? null,
                'firmada'         => $beforeDecl['firmada'] ?? null,
                'fecha'           => $this->mongoDateToString($beforeDecl['createdAt'] ?? null),
            ] : null,
        ]);

        $target = $data['return_to'] ?? null;
        if ($target && str_starts_with($target, '/')) {
            return redirect($target)->with('ok', 'Declaración eliminada.');
        }
        return redirect()->route('instances.inspect', ['slug' => $slug, 'collection' => 'declaraciones'])
            ->with('ok', 'Declaración eliminada.');
    }

    // ── Helpers privados ─────────────────────────────────────────

    /**
     * Compara los campos enviados contra los valores actuales en Mongo
     * y devuelve solo los campos que cambian, con formato before/after.
     */
    private function userDiff(array $submitted, ?array $before): array
    {
        $diff = ['before' => [], 'after' => []];

        $simpleFields = ['username', 'nombre', 'primerApellido', 'segundoApellido', 'curp', 'rfc'];
        foreach ($simpleFields as $field) {
            if (array_key_exists($field, $submitted) && $submitted[$field] !== null) {
                $old = $before[$field] ?? null;
                $new = $submitted[$field];
                // Comparación case-insensitive para campos que el servicio normaliza
                if (mb_strtoupper((string) $old) !== mb_strtoupper((string) $new)) {
                    $diff['before'][$field] = $old;
                    $diff['after'][$field]  = $new;
                }
            }
        }

        foreach (['clave' => 'institucion_clave', 'valor' => 'institucion_valor'] as $mongoKey => $formKey) {
            if (array_key_exists($formKey, $submitted) && $submitted[$formKey] !== null) {
                $old = $before['institucion'][$mongoKey] ?? null;
                $new = $submitted[$formKey];
                if ($old !== $new) {
                    $diff['before']['institucion.'.$mongoKey] = $old;
                    $diff['after']['institucion.'.$mongoKey]  = $new;
                }
            }
        }

        if (empty($diff['before']) && empty($diff['after'])) return [];
        return $diff;
    }

    /**
     * Convierte la representación JSON de un UTCDateTime de MongoDB a
     * una cadena de fecha legible (Y-m-d).
     * El formato extendido de BSON es: {"$date": {"$numberLong": "ms"}}
     */
    private function mongoDateToString(mixed $raw): ?string
    {
        if (! $raw) return null;
        $ms = $raw['$date']['$numberLong'] ?? null;
        if ($ms === null) return null;
        try {
            return \Carbon\Carbon::createFromTimestampMs((int) $ms)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
