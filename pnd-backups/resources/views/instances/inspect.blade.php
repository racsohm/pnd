@extends('layouts.app')

@section('content')
<a href="{{ route('instances.show', $instance['slug']) }}" class="text-sm text-slate-500 hover:underline">← {{ $instance['name'] }}</a>

<div class="mt-2 mb-5">
  <h1 class="text-2xl font-semibold">Inspector</h1>
  <p class="text-sm text-slate-500">
    Lectura directa sobre <code>{{ $instance['mongo_db'] }}</code>.
    Usuarios: <em>editar datos / contraseña / rol</em>. Declaraciones: <em>cambiar fecha o eliminar</em>.
  </p>
</div>

@if ($error)
  <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
    <strong>Error consultando Mongo:</strong> {{ $error }}
  </div>
@endif

<div class="flex gap-1 mb-4 border-b">
  @foreach ($collections as $name)
    @php $isActive = $name === $collection; @endphp
    <a href="{{ route('instances.inspect', ['slug' => $instance['slug'], 'collection' => $name]) }}"
       class="px-4 py-2 text-sm border-b-2 -mb-px
              {{ $isActive ? 'border-slate-900 text-slate-900 font-semibold' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
      {{ $name }}
      <span class="ml-1 text-xs text-slate-400">({{ $totals[$name] ?? '—' }})</span>
    </a>
  @endforeach
</div>

<form method="GET" class="flex gap-2 mb-4">
  <input type="hidden" name="collection" value="{{ $collection }}">
  <input type="text" name="q" value="{{ $q }}"
         placeholder="Buscar por _id (24 hex), email o username…"
         class="flex-1 rounded border-slate-300 text-sm" />
  <button class="rounded bg-slate-900 text-white px-3 py-2 text-sm hover:bg-slate-800">
    Buscar
  </button>
  @if ($q !== '')
    <a href="{{ route('instances.inspect', ['slug' => $instance['slug'], 'collection' => $collection]) }}"
       class="rounded border px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
      Limpiar
    </a>
  @endif
</form>

<div class="rounded-lg border bg-white overflow-hidden">
  <div class="px-4 py-3 border-b flex items-center justify-between">
    <span class="font-semibold">{{ $collection }}</span>
    <span class="text-xs text-slate-500">
      @if ($q !== '')
        {{ count($docs) }} resultado(s) para «{{ $q }}»
      @else
        {{ count($docs) }} documentos (los {{ $pageSize }} más recientes por <code>_id</code>)
      @endif
    </span>
  </div>

  @if (empty($docs))
    <div class="p-6 text-sm text-slate-500 text-center">Sin resultados.</div>
  @else
    <div class="divide-y">
      @foreach ($docs as $i => $doc)
        @php
          $id    = data_get($doc, '_id.$oid', data_get($doc, '_id', '—'));
          $email = data_get($doc, 'email');
          $user  = data_get($doc, 'username');
          $name  = data_get($doc, 'nombre', data_get($doc, 'name'));
          $isUserDoc = $collection === 'users' && preg_match('/^[a-f0-9]{24}$/i', (string) $id);
          $isDeclDoc = $collection === 'declaraciones' && preg_match('/^[a-f0-9]{24}$/i', (string) $id);
          $createdMs = data_get($doc, 'createdAt.$date.$numberLong')
                     ?? data_get($doc, 'createdAt.$date');
          $createdAt = null;
          if (is_numeric($createdMs)) {
            $createdAt = \Carbon\Carbon::createFromTimestampMs((int) $createdMs);
          } elseif (is_string($createdMs)) {
            try { $createdAt = \Carbon\Carbon::parse($createdMs); } catch (\Throwable) {}
          }
          $fechaInput = $createdAt ? $createdAt->format('Y-m-d\TH:i') : '';
          $tipoDecl   = data_get($doc, 'tipoDeclaracion');
          $anio       = data_get($doc, 'anioEjercicio');
          $returnTo   = url()->full();
        @endphp
        <div class="px-4 py-3 group border-b last:border-0"
             x-data="{ editingFecha: false, deleting: false }">
          <details @if ($i === 0 && $q !== '') open @endif>
            <summary class="cursor-pointer flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <span class="font-mono text-xs text-slate-700">{{ $id }}</span>
              @if ($email)
                <span class="text-slate-400">·</span>
                <span class="text-slate-700">{{ $email }}</span>
              @endif
              @if ($user)
                <span class="text-slate-400">·</span>
                <span class="text-slate-700">{{ $user }}</span>
              @endif
              @if ($name && is_string($name))
                <span class="text-slate-400">·</span>
                <span class="text-slate-700">{{ $name }}</span>
              @endif
              @if ($tipoDecl)
                <span class="text-slate-400">·</span>
                <span class="text-slate-700">{{ $tipoDecl }}@if ($anio) {{ $anio }}@endif</span>
              @endif
              @if ($createdAt)
                <span class="text-slate-400">·</span>
                <span class="text-slate-500">{{ $createdAt->format('Y-m-d H:i') }}</span>
              @endif
              <span class="ml-auto text-xs text-slate-400">
                <span class="details-toggle-open">▸ ver doc</span>
              </span>
            </summary>
            <pre class="mt-2 text-xs bg-slate-50 border rounded p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
          </details>

          @if ($isUserDoc || $isDeclDoc)
            <div class="mt-2 flex gap-3 text-sm">
              @if ($isUserDoc)
                <a href="{{ route('users.edit', ['slug' => $instance['slug'], 'id' => $id]) }}"
                   class="text-slate-700 hover:underline">Editar usuario →</a>
              @endif
              @if ($isDeclDoc)
                <button type="button" @click="editingFecha = true"
                        class="text-slate-700 hover:underline">Editar fecha</button>
                <button type="button" @click="deleting = true"
                        class="text-rose-700 hover:underline">Eliminar declaración</button>
              @endif
            </div>

            @if ($isDeclDoc)
              <div x-show="editingFecha" x-cloak
                   class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <form method="POST"
                      action="{{ route('declaraciones.fecha', ['slug' => $instance['slug'], 'id' => $id]) }}"
                      @click.away="editingFecha = false"
                      class="bg-white rounded-lg shadow-xl w-full max-w-md p-5 text-left">
                  @csrf @method('PUT')
                  <input type="hidden" name="return_to" value="{{ $returnTo }}">
                  <h3 class="text-lg font-semibold mb-2">Editar fecha de declaración</h3>
                  <p class="text-sm text-slate-600 mb-3">
                    <code class="text-xs">{{ $id }}</code>
                  </p>
                  <label class="block text-sm mb-1">Nueva fecha y hora (createdAt)</label>
                  <input type="datetime-local" name="fecha" required value="{{ $fechaInput }}"
                         class="w-full rounded border-slate-300 text-sm mb-3" />
                  <label class="block text-sm mb-1">Año de ejercicio (opcional)</label>
                  <input type="number" name="anioEjercicio" min="1900" max="2100"
                         value="{{ $anio }}"
                         class="w-full rounded border-slate-300 text-sm mb-4" />
                  <div class="flex justify-end gap-2">
                    <button type="button" @click="editingFecha = false"
                            class="rounded border px-3 py-2 text-sm">Cancelar</button>
                    <button class="rounded bg-slate-900 text-white px-3 py-2 text-sm hover:bg-slate-800">
                      Guardar
                    </button>
                  </div>
                </form>
              </div>

              <div x-show="deleting" x-cloak
                   class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <form method="POST"
                      action="{{ route('declaraciones.destroy', ['slug' => $instance['slug'], 'id' => $id]) }}"
                      @click.away="deleting = false"
                      class="bg-white rounded-lg shadow-xl w-full max-w-md p-5 text-left">
                  @csrf @method('DELETE')
                  <input type="hidden" name="return_to" value="{{ $returnTo }}">
                  <h3 class="text-lg font-semibold mb-2 text-rose-700">Eliminar declaración</h3>
                  <p class="text-sm text-slate-600 mb-3">
                    Vas a borrar <code class="text-xs">{{ $id }}</code>. Acción
                    <strong>irreversible</strong>.
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
            @endif
          @endif
        </div>
      @endforeach
    </div>
  @endif
</div>
@endsection
