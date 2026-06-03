<?php

namespace App\Http\Controllers;

use App\Models\InstancePermission;
use App\Models\User;
use App\Services\InstanceDiscovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InstanceMemberController extends Controller
{
    public function __construct(private InstanceDiscovery $discovery) {}

    private function resolveInstance(string $slug): array
    {
        $inst = $this->discovery->find($slug);
        if (! $inst) {
            throw new NotFoundHttpException("Instancia '$slug' no encontrada.");
        }
        if (! Auth::user()->canManageUsersFor($slug)) {
            abort(403);
        }
        return $inst;
    }

    public function index(string $slug)
    {
        $instance = $this->resolveInstance($slug);

        $members = User::where('instance_slug', $slug)
            ->with('instancePermissions')
            ->orderBy('name')
            ->get();

        return view('instances.members.index', compact('instance', 'members'));
    }

    public function create(string $slug)
    {
        $instance = $this->resolveInstance($slug);
        return view('instances.members.create', compact('instance'));
    }

    public function store(Request $request, string $slug)
    {
        $instance = $this->resolveInstance($slug);

        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:100'],
            'email'                => ['required', 'email', 'unique:users,email'],
            'password'             => ['required', 'string', 'min:8', 'confirmed'],
            'role'                 => ['required', 'in:instance_admin,instance_viewer'],
            'can_manage_users'     => ['nullable', 'boolean'],
            'can_generate_backups' => ['nullable', 'boolean'],
            'can_view_stats'       => ['nullable', 'boolean'],
            'can_download_reports' => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name'          => $data['name'],
            'email'         => $data['email'],
            'password'      => Hash::make($data['password']),
            'role'          => $data['role'],
            'instance_slug' => $slug,
        ]);

        InstancePermission::create([
            'user_id'              => $user->id,
            'instance_slug'        => $slug,
            'can_manage_users'     => (bool) ($data['can_manage_users'] ?? false),
            'can_generate_backups' => (bool) ($data['can_generate_backups'] ?? false),
            'can_view_stats'       => (bool) ($data['can_view_stats'] ?? true),
            'can_download_reports' => (bool) ($data['can_download_reports'] ?? false),
        ]);

        return redirect()->route('instances.members.index', $slug)
            ->with('ok', "Usuario '{$user->name}' creado correctamente.");
    }

    public function edit(string $slug, User $member)
    {
        $instance = $this->resolveInstance($slug);

        if ($member->instance_slug !== $slug) {
            abort(404);
        }

        $perms = $member->permissionsFor($slug);

        return view('instances.members.edit', compact('instance', 'member', 'perms'));
    }

    public function update(Request $request, string $slug, User $member)
    {
        $instance = $this->resolveInstance($slug);

        if ($member->instance_slug !== $slug) {
            abort(404);
        }

        // No puede escalar a super_admin desde aquí
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:100'],
            'email'                => ['required', 'email', "unique:users,email,{$member->id}"],
            'role'                 => ['required', 'in:instance_admin,instance_viewer'],
            'can_manage_users'     => ['nullable', 'boolean'],
            'can_generate_backups' => ['nullable', 'boolean'],
            'can_view_stats'       => ['nullable', 'boolean'],
            'can_download_reports' => ['nullable', 'boolean'],
            'password'             => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $member->update([
            'name'  => $data['name'],
            'email' => $data['email'],
            'role'  => $data['role'],
        ]);

        if (! empty($data['password'])) {
            $member->update(['password' => Hash::make($data['password'])]);
        }

        InstancePermission::updateOrCreate(
            ['user_id' => $member->id, 'instance_slug' => $slug],
            [
                'can_manage_users'     => (bool) ($data['can_manage_users'] ?? false),
                'can_generate_backups' => (bool) ($data['can_generate_backups'] ?? false),
                'can_view_stats'       => (bool) ($data['can_view_stats'] ?? true),
                'can_download_reports' => (bool) ($data['can_download_reports'] ?? false),
            ]
        );

        return redirect()->route('instances.members.index', $slug)
            ->with('ok', "Usuario '{$member->name}' actualizado.");
    }

    public function destroy(string $slug, User $member)
    {
        $this->resolveInstance($slug);

        if ($member->instance_slug !== $slug) {
            abort(404);
        }

        // No puede eliminar su propia cuenta
        if ($member->id === Auth::id()) {
            return back()->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        $name = $member->name;
        $member->delete();

        return redirect()->route('instances.members.index', $slug)
            ->with('ok', "Usuario '$name' eliminado.");
    }
}
