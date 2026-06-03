@extends('layouts.app')

@section('content')
<a href="{{ route('instances.show', $instance['slug']) }}"
   class="text-sm text-slate-500 hover:underline">← {{ $instance['name'] }}</a>

<div class="flex items-center justify-between mt-2 mb-6">
  <div>
    <h1 class="text-2xl font-semibold">Usuarios de la instancia</h1>
    <p class="text-sm text-slate-500">Administradores y lectores con acceso a <strong>{{ $instance['name'] }}</strong>.</p>
  </div>
  @if (auth()->user()->canManageUsersFor($instance['slug']))
    <a href="{{ route('instances.members.create', $instance['slug']) }}"
       class="rounded bg-slate-900 text-white px-3 py-2 text-sm hover:bg-slate-800">
      + Agregar usuario
    </a>
  @endif
</div>

@if ($members->isEmpty())
  <div class="rounded-lg border bg-white p-10 text-center text-sm text-slate-500">
    Aún no hay usuarios de instancia. Agrega el primero.
  </div>
@else
  <div class="rounded-lg border bg-white overflow-hidden">
    <table class="w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500 border-b bg-slate-50">
        <tr>
          <th class="px-4 py-3">Nombre</th>
          <th class="px-4 py-3">Correo</th>
          <th class="px-4 py-3">Rol</th>
          <th class="px-4 py-3">Permisos</th>
          <th class="px-4 py-3 text-right">Acciones</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($members as $member)
          @php $perms = $member->permissionsFor($instance['slug']); @endphp
          <tr class="border-b last:border-0 hover:bg-slate-50">
            <td class="px-4 py-3 font-medium">{{ $member->name }}</td>
            <td class="px-4 py-3 text-slate-600">{{ $member->email }}</td>
            <td class="px-4 py-3">
              @if ($member->isInstanceAdmin())
                <span class="inline-block rounded bg-blue-100 text-blue-700 px-2 py-0.5 text-xs font-medium">
                  Admin
                </span>
              @else
                <span class="inline-block rounded bg-slate-100 text-slate-600 px-2 py-0.5 text-xs">
                  Lector
                </span>
              @endif
            </td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-1">
                @if ($perms?->can_manage_users)
                  <span class="text-xs bg-violet-100 text-violet-700 px-1.5 py-0.5 rounded">Usuarios</span>
                @endif
                @if ($perms?->can_generate_backups)
                  <span class="text-xs bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded">Respaldos</span>
                @endif
                @if ($perms?->can_view_stats)
                  <span class="text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">Estadísticas</span>
                @endif
                @if ($perms?->can_download_reports)
                  <span class="text-xs bg-sky-100 text-sky-700 px-1.5 py-0.5 rounded">Informes</span>
                @endif
              </div>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <a href="{{ route('instances.members.edit', [$instance['slug'], $member->id]) }}"
                 class="text-slate-700 hover:underline text-sm">Editar</a>
              <form method="POST"
                    action="{{ route('instances.members.destroy', [$instance['slug'], $member->id]) }}"
                    class="inline ml-3"
                    onsubmit="return confirm('¿Eliminar a {{ $member->name }}?')">
                @csrf @method('DELETE')
                <button class="text-rose-700 hover:underline text-sm">Eliminar</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
@endsection
