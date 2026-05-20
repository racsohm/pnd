<?php

namespace App\Http\Controllers;

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

        try {
            $this->editor->updateUser($slug, $id, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'No se pudo guardar: '.$e->getMessage());
        }

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

        try {
            $this->editor->resetPassword($slug, $id, $data['password']);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo cambiar la contraseña: '.$e->getMessage());
        }

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

        try {
            $this->editor->setRoles($slug, $id, $data['roles']);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo cambiar el rol: '.$e->getMessage());
        }

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

        try {
            $this->editor->deleteDeclaracion($slug, $id);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo eliminar: '.$e->getMessage());
        }

        $target = $data['return_to'] ?? null;
        if ($target && str_starts_with($target, '/')) {
            return redirect($target)->with('ok', 'Declaración eliminada.');
        }
        return redirect()->route('instances.inspect', ['slug' => $slug, 'collection' => 'declaraciones'])
            ->with('ok', 'Declaración eliminada.');
    }
}
