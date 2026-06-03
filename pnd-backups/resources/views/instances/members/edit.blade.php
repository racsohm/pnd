@extends('layouts.app')

@section('content')
<a href="{{ route('instances.members.index', $instance['slug']) }}"
   class="text-sm text-slate-500 hover:underline">← Usuarios de {{ $instance['name'] }}</a>

<h1 class="text-2xl font-semibold mt-2 mb-6">Editar usuario</h1>

<form method="POST" action="{{ route('instances.members.update', [$instance['slug'], $member->id]) }}"
      class="max-w-lg space-y-5">
  @csrf @method('PUT')

  <div>
    <label class="block text-sm font-medium mb-1">Nombre</label>
    <input type="text" name="name" value="{{ old('name', $member->name) }}" required
           class="w-full rounded border-slate-300 text-sm @error('name') border-rose-400 @enderror" />
    @error('name')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
  </div>

  <div>
    <label class="block text-sm font-medium mb-1">Correo electrónico</label>
    <input type="email" name="email" value="{{ old('email', $member->email) }}" required
           class="w-full rounded border-slate-300 text-sm @error('email') border-rose-400 @enderror" />
    @error('email')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
  </div>

  <div class="grid grid-cols-2 gap-4">
    <div>
      <label class="block text-sm font-medium mb-1">Nueva contraseña <span class="text-slate-400 font-normal">(opcional)</span></label>
      <input type="password" name="password"
             class="w-full rounded border-slate-300 text-sm @error('password') border-rose-400 @enderror" />
      @error('password')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Confirmar contraseña</label>
      <input type="password" name="password_confirmation"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
  </div>

  <div>
    <label class="block text-sm font-medium mb-1">Rol</label>
    <select id="role-select" name="role" required class="w-full rounded border-slate-300 text-sm">
      <option value="instance_admin"
              {{ old('role', $member->role) === 'instance_admin' ? 'selected' : '' }}>
        Admin de instancia
      </option>
      <option value="instance_viewer"
              {{ old('role', $member->role) === 'instance_viewer' ? 'selected' : '' }}>
        Lector
      </option>
    </select>
  </div>

  <fieldset class="rounded-lg border p-4">
    <legend class="text-sm font-medium px-1">Permisos</legend>
    <div class="space-y-3 mt-2">
      <label class="flex items-start gap-3">
        <input type="checkbox" name="can_manage_users" value="1"
               {{ old('can_manage_users', $perms?->can_manage_users) ? 'checked' : '' }}
               class="mt-0.5 rounded" />
        <span class="text-sm">
          <span class="font-medium">Gestionar usuarios</span>
          <span class="block text-xs text-slate-500">Puede agregar, editar y eliminar usuarios de esta instancia.</span>
        </span>
      </label>
      <label class="flex items-start gap-3">
        <input type="checkbox" name="can_generate_backups" value="1"
               {{ old('can_generate_backups', $perms?->can_generate_backups) ? 'checked' : '' }}
               class="mt-0.5 rounded" />
        <span class="text-sm">
          <span class="font-medium">Generar respaldos</span>
          <span class="block text-xs text-slate-500">Puede crear y descargar respaldos de MongoDB.</span>
        </span>
      </label>
      <label class="flex items-start gap-3">
        <input type="checkbox" name="can_view_stats" value="1"
               {{ old('can_view_stats', $perms?->can_view_stats ?? true) ? 'checked' : '' }}
               class="mt-0.5 rounded" />
        <span class="text-sm">
          <span class="font-medium">Ver estadísticas</span>
          <span class="block text-xs text-slate-500">Puede ver el dashboard de estadísticas de la instancia.</span>
        </span>
      </label>
      <label class="flex items-start gap-3">
        <input type="checkbox" name="can_download_reports" value="1"
               {{ old('can_download_reports', $perms?->can_download_reports) ? 'checked' : '' }}
               class="mt-0.5 rounded" />
        <span class="text-sm">
          <span class="font-medium">Descargar informes</span>
          <span class="block text-xs text-slate-500">Puede exportar declaraciones en Excel, PDF y ZIP.</span>
        </span>
      </label>
    </div>
  </fieldset>

  <div class="flex gap-3">
    <button type="submit"
            class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
      Guardar cambios
    </button>
    <a href="{{ route('instances.members.index', $instance['slug']) }}"
       class="rounded border px-4 py-2 text-sm hover:bg-slate-50">
      Cancelar
    </a>
  </div>
</form>

<script>
(function () {
  const presets = {
    instance_admin:  { can_manage_users: true,  can_generate_backups: true,  can_view_stats: true,  can_download_reports: true  },
    instance_viewer: { can_manage_users: false, can_generate_backups: false, can_view_stats: true,  can_download_reports: false },
  };

  const sel = document.getElementById('role-select');

  sel.addEventListener('change', function () {
    const p = presets[this.value];
    if (!p) return;
    Object.entries(p).forEach(([name, val]) => {
      const cb = document.querySelector(`input[name="${name}"]`);
      if (cb) cb.checked = val;
    });
  });
}());
</script>
@endsection
