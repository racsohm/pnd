@extends('layouts.app')

@section('content')
@php
  $userIdOid = data_get($user, '_id.$oid', $userId);
  $username  = data_get($user, 'username');
  $rolesAct  = (array) data_get($user, 'roles', []);
@endphp

<a href="{{ route('instances.inspect', ['slug' => $instance['slug'], 'collection' => 'users']) }}"
   class="text-sm text-slate-500 hover:underline">← Inspector ({{ $instance['name'] }})</a>

<div class="mt-2 mb-5">
  <h1 class="text-2xl font-semibold">Editar usuario</h1>
  <p class="text-sm text-slate-500">
    <code class="text-xs">{{ $userIdOid }}</code>
    @if ($username) · <span class="text-slate-700">{{ $username }}</span> @endif
  </p>
</div>

@if ($errors->any())
  <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
    <ul class="list-disc list-inside">
      @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
    </ul>
  </div>
@endif

{{-- ── Datos personales ───────────────────────────────────── --}}
<form method="POST"
      action="{{ route('users.update', ['slug' => $instance['slug'], 'id' => $userId]) }}"
      class="rounded-lg border bg-white p-5 mb-6 space-y-4">
  @csrf @method('PUT')
  <h2 class="font-semibold">Datos personales</h2>

  <div class="grid gap-4 md:grid-cols-2">
    <div>
      <label class="block text-sm font-medium mb-1">Username / correo de login</label>
      <input type="text" name="username"
             value="{{ old('username', $username) }}"
             class="w-full rounded border-slate-300 text-sm" />
      <p class="text-xs text-slate-500 mt-1">Se guarda en minúsculas. Debe ser único.</p>
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">CURP</label>
      <input type="text" name="curp"
             value="{{ old('curp', data_get($user, 'curp')) }}"
             class="w-full rounded border-slate-300 text-sm uppercase" />
    </div>
  </div>

  <div class="grid gap-4 md:grid-cols-3">
    <div>
      <label class="block text-sm font-medium mb-1">Nombre(s)</label>
      <input type="text" name="nombre"
             value="{{ old('nombre', data_get($user, 'nombre')) }}"
             class="w-full rounded border-slate-300 text-sm uppercase" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Primer apellido</label>
      <input type="text" name="primerApellido"
             value="{{ old('primerApellido', data_get($user, 'primerApellido')) }}"
             class="w-full rounded border-slate-300 text-sm uppercase" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Segundo apellido</label>
      <input type="text" name="segundoApellido"
             value="{{ old('segundoApellido', data_get($user, 'segundoApellido')) }}"
             class="w-full rounded border-slate-300 text-sm uppercase" />
    </div>
  </div>

  <div class="grid gap-4 md:grid-cols-3">
    <div>
      <label class="block text-sm font-medium mb-1">RFC</label>
      <input type="text" name="rfc"
             value="{{ old('rfc', data_get($user, 'rfc')) }}"
             class="w-full rounded border-slate-300 text-sm uppercase" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Institución — clave</label>
      <input type="text" name="institucion_clave"
             value="{{ old('institucion_clave', data_get($user, 'institucion.clave')) }}"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Institución — nombre</label>
      <input type="text" name="institucion_valor"
             value="{{ old('institucion_valor', data_get($user, 'institucion.valor')) }}"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
  </div>

  <div class="pt-2">
    <button class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
      Guardar datos
    </button>
  </div>
</form>

{{-- ── Contraseña ─────────────────────────────────────────── --}}
<form method="POST"
      action="{{ route('users.password', ['slug' => $instance['slug'], 'id' => $userId]) }}"
      class="rounded-lg border bg-white p-5 mb-6 space-y-4"
      onsubmit="return confirm('¿Cambiar la contraseña de este usuario?')">
  @csrf @method('PUT')
  <h2 class="font-semibold">Restablecer contraseña</h2>
  <p class="text-xs text-slate-500">
    Sustituye la contraseña sin requerir la actual. El usuario puede entrar inmediatamente con la nueva.
  </p>
  <div class="grid gap-4 md:grid-cols-2">
    <div>
      <label class="block text-sm font-medium mb-1">Nueva contraseña</label>
      <input type="password" name="password" required minlength="8"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Confirmar</label>
      <input type="password" name="password_confirmation" required minlength="8"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
  </div>
  <div class="pt-2">
    <button class="rounded bg-amber-600 text-white px-4 py-2 text-sm hover:bg-amber-700">
      Cambiar contraseña
    </button>
  </div>
</form>

{{-- ── Roles ──────────────────────────────────────────────── --}}
<form method="POST"
      action="{{ route('users.roles', ['slug' => $instance['slug'], 'id' => $userId]) }}"
      class="rounded-lg border bg-white p-5 mb-6 space-y-4">
  @csrf @method('PUT')
  <h2 class="font-semibold">Rol</h2>
  <p class="text-xs text-slate-500">
    Reemplaza el array <code>roles</code>. Debe elegirse al menos uno.
  </p>
  <div class="flex flex-wrap gap-3">
    @foreach ($roles as $r)
      @php $checked = in_array($r, old('roles', $rolesAct), true); @endphp
      <label class="flex items-center gap-2 text-sm border rounded px-3 py-2 cursor-pointer
                    {{ $checked ? 'border-slate-900 bg-slate-50' : 'border-slate-200' }}">
        <input type="checkbox" name="roles[]" value="{{ $r }}"
               @checked($checked)
               class="rounded">
        <span class="font-mono">{{ $r }}</span>
      </label>
    @endforeach
  </div>
  <div class="pt-2">
    <button class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
      Guardar roles
    </button>
  </div>
</form>

{{-- ── Declaraciones del usuario ──────────────────────────── --}}
<div class="rounded-lg border bg-white mb-6">
  <div class="px-5 py-3 border-b flex items-center justify-between">
    <h2 class="font-semibold">Declaraciones de este usuario</h2>
    <span class="text-xs text-slate-500">{{ count($declaraciones) }} total</span>
  </div>

  @if (empty($declaraciones))
    <div class="p-6 text-sm text-slate-500 text-center">Sin declaraciones registradas.</div>
  @else
    <table class="w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500">
        <tr class="border-b">
          <th class="px-4 py-2">Tipo</th>
          <th class="px-4 py-2">Año</th>
          <th class="px-4 py-2">Creada</th>
          <th class="px-4 py-2">Estado</th>
          <th class="px-4 py-2 text-right">Acciones</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($declaraciones as $d)
          @php
            $declId   = data_get($d, '_id.$oid', '—');
            $tipo     = data_get($d, 'tipoDeclaracion', '—');
            $anio     = data_get($d, 'anioEjercicio');
            $createdMs= data_get($d, 'createdAt.$date.$numberLong')
                       ?? data_get($d, 'createdAt.$date');
            $created  = null;
            if (is_numeric($createdMs)) {
              $created = \Carbon\Carbon::createFromTimestampMs((int) $createdMs);
            } elseif (is_string($createdMs)) {
              try { $created = \Carbon\Carbon::parse($createdMs); } catch (\Throwable) {}
            }
            $firmada  = (bool) data_get($d, 'firmada', false);
            $completa = (bool) data_get($d, 'declaracionCompleta', false);
            $fechaInput = $created ? $created->format('Y-m-d\TH:i') : '';
            $returnTo = route('users.edit', ['slug' => $instance['slug'], 'id' => $userId]);
          @endphp
          <tr class="border-b last:border-0 align-top hover:bg-slate-50"
              x-data="{ editing: false, deleting: false }">
            <td class="px-4 py-3">
              <div class="font-medium">{{ $tipo }}</div>
              <div class="font-mono text-[11px] text-slate-400">{{ $declId }}</div>
            </td>
            <td class="px-4 py-3">{{ $anio ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-600">
              {{ $created ? $created->format('Y-m-d H:i') : '—' }}
            </td>
            <td class="px-4 py-3 text-xs">
              @if ($firmada) <span class="inline-block rounded bg-emerald-100 text-emerald-700 px-2 py-0.5">firmada</span> @endif
              @if (! $completa) <span class="inline-block rounded bg-amber-100 text-amber-700 px-2 py-0.5">incompleta</span> @endif
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button type="button" @click="editing = true"
                      class="text-slate-700 hover:underline text-sm">Editar fecha</button>
              <button type="button" @click="deleting = true"
                      class="text-rose-700 hover:underline text-sm ml-2">Eliminar</button>

              {{-- Modal: editar fecha --}}
              <div x-show="editing" x-cloak
                   class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <form method="POST"
                      action="{{ route('declaraciones.fecha', ['slug' => $instance['slug'], 'id' => $declId]) }}"
                      @click.away="editing = false"
                      class="bg-white rounded-lg shadow-xl w-full max-w-md p-5 text-left">
                  @csrf @method('PUT')
                  <input type="hidden" name="return_to" value="{{ $returnTo }}">
                  <h3 class="text-lg font-semibold mb-2">Editar fecha de declaración</h3>
                  <p class="text-sm text-slate-600 mb-3">
                    Cambia <code>createdAt</code> de la declaración <strong>{{ $tipo }}</strong>.
                    <span class="text-xs text-slate-500 block mt-1">
                      <code>updatedAt</code> se sella a la hora actual automáticamente.
                    </span>
                  </p>
                  <label class="block text-sm mb-1">Nueva fecha y hora</label>
                  <input type="datetime-local" name="fecha" required value="{{ $fechaInput }}"
                         class="w-full rounded border-slate-300 text-sm mb-3" />
                  <label class="block text-sm mb-1">Año de ejercicio (opcional)</label>
                  <input type="number" name="anioEjercicio" min="1900" max="2100"
                         value="{{ $anio }}"
                         class="w-full rounded border-slate-300 text-sm mb-4" />
                  <div class="flex justify-end gap-2">
                    <button type="button" @click="editing = false"
                            class="rounded border px-3 py-2 text-sm">Cancelar</button>
                    <button class="rounded bg-slate-900 text-white px-3 py-2 text-sm hover:bg-slate-800">
                      Guardar
                    </button>
                  </div>
                </form>
              </div>

              {{-- Modal: eliminar --}}
              <div x-show="deleting" x-cloak
                   class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <form method="POST"
                      action="{{ route('declaraciones.destroy', ['slug' => $instance['slug'], 'id' => $declId]) }}"
                      @click.away="deleting = false"
                      class="bg-white rounded-lg shadow-xl w-full max-w-md p-5 text-left">
                  @csrf @method('DELETE')
                  <input type="hidden" name="return_to" value="{{ $returnTo }}">
                  <h3 class="text-lg font-semibold mb-2 text-rose-700">Eliminar declaración</h3>
                  <p class="text-sm text-slate-600 mb-3">
                    Vas a borrar la declaración <strong>{{ $tipo }}</strong>
                    (<code class="text-xs">{{ $declId }}</code>). Esta acción es
                    <strong>irreversible</strong> y también la quita del array
                    <code>declaraciones</code> del usuario.
                  </p>
                  <label class="block text-sm mb-1">
                    Escribe <code class="bg-slate-100 px-1 rounded">ELIMINAR</code> para confirmar:
                  </label>
                  <input name="confirm" required
                         class="w-full rounded border-slate-300 text-sm mb-4" />
                  <div class="flex justify-end gap-2">
                    <button type="button" @click="deleting = false"
                            class="rounded border px-3 py-2 text-sm">Cancelar</button>
                    <button class="rounded bg-rose-600 text-white px-3 py-2 text-sm hover:bg-rose-700">
                      Eliminar definitivamente
                    </button>
                  </div>
                </form>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
