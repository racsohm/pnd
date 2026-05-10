@extends('layouts.app')

@section('content')
<a href="{{ route('dashboard') }}" class="text-sm text-slate-500 hover:underline">← Instancias</a>

<div class="flex items-center justify-between mt-2 mb-5">
  <div>
    <h1 class="text-2xl font-semibold">{{ $instance['name'] }}</h1>
    <p class="text-sm text-slate-500">
      DB: <code>{{ $instance['mongo_db'] }}</code> · Host: <code>{{ $instance['mongo_host'] }}:{{ $instance['mongo_port'] }}</code>
    </p>
  </div>
</div>

<div class="grid gap-4 md:grid-cols-2 mb-6">
  <form method="POST" action="{{ route('backups.store', $instance['slug']) }}"
        class="rounded-lg border bg-white p-4">
    @csrf
    <h2 class="font-semibold mb-1">Crear respaldo ahora</h2>
    <p class="text-xs text-slate-500 mb-3">
      Ejecuta <code>mongodump</code> contra esta instancia y guarda el .gz.
    </p>
    <button class="rounded bg-slate-900 text-white px-3 py-2 text-sm hover:bg-slate-800">
      Generar respaldo
    </button>
  </form>

  <form method="POST" action="{{ route('backups.upload', $instance['slug']) }}"
        enctype="multipart/form-data"
        class="rounded-lg border bg-white p-4">
    @csrf
    <h2 class="font-semibold mb-1">Subir respaldo (.gz)</h2>
    <p class="text-xs text-slate-500 mb-3">
      Sube un archivo generado con <code>mongodump --gzip --archive=…</code>.
      Máximo {{ config('backups.upload_max_mb') }} MB.
    </p>
    <input type="file" name="archive" accept=".gz,application/gzip" required
           class="block w-full text-sm mb-2" />
    @error('archive')<p class="text-xs text-rose-600 mb-2">{{ $message }}</p>@enderror
    <input type="text" name="notes" placeholder="Notas (opcional)"
           class="w-full rounded border-slate-300 text-sm mb-2" />
    <button class="rounded bg-slate-700 text-white px-3 py-2 text-sm hover:bg-slate-600">
      Subir
    </button>
  </form>
</div>

<div class="rounded-lg border bg-white">
  <div class="px-4 py-3 border-b font-semibold">Respaldos disponibles</div>
  @if ($backups->isEmpty())
    <div class="p-6 text-sm text-slate-500 text-center">Aún no hay respaldos.</div>
  @else
    <table class="w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500">
        <tr class="border-b">
          <th class="px-4 py-2">Archivo</th>
          <th class="px-4 py-2">Origen</th>
          <th class="px-4 py-2">Tamaño</th>
          <th class="px-4 py-2">Fecha</th>
          <th class="px-4 py-2 text-right">Acciones</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($backups as $b)
          <tr class="border-b last:border-0 hover:bg-slate-50" x-data="{ open: false }">
            <td class="px-4 py-2 font-mono text-xs">{{ $b->filename }}</td>
            <td class="px-4 py-2">
              @if ($b->source === 'dump')
                <span class="inline-block rounded bg-emerald-100 text-emerald-700 px-2 py-0.5 text-xs">dump</span>
              @else
                <span class="inline-block rounded bg-amber-100 text-amber-700 px-2 py-0.5 text-xs">subido</span>
              @endif
            </td>
            <td class="px-4 py-2">{{ $b->humanSize() }}</td>
            <td class="px-4 py-2 text-slate-500">{{ $b->created_at->format('Y-m-d H:i') }}</td>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <a href="{{ route('backups.download', $b->id) }}"
                 class="text-slate-700 hover:underline text-sm">Descargar</a>
              <button @click="open = true" type="button"
                      class="text-amber-700 hover:underline text-sm ml-2">Restaurar</button>
              <form method="POST" action="{{ route('backups.destroy', $b->id) }}"
                    class="inline ml-2"
                    onsubmit="return confirm('¿Eliminar definitivamente {{ $b->filename }}?')">
                @csrf @method('DELETE')
                <button class="text-rose-700 hover:underline text-sm">Eliminar</button>
              </form>

              {{-- Modal de restore --}}
              <div x-show="open" x-cloak
                   class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <form method="POST" action="{{ route('backups.restore', $b->id) }}"
                      @click.away="open = false"
                      class="bg-white rounded-lg shadow-xl w-full max-w-md p-5 text-left">
                  @csrf
                  <h3 class="text-lg font-semibold mb-2">Restaurar respaldo</h3>
                  <p class="text-sm text-slate-600 mb-3">
                    Vas a restaurar <code class="text-xs">{{ $b->filename }}</code>
                    en la base <strong>{{ $instance['mongo_db'] }}</strong>.
                  </p>
                  <label class="flex items-start gap-2 text-sm mb-3">
                    <input type="checkbox" name="drop" value="1" checked class="mt-1 rounded">
                    <span>
                      <strong>Reemplazar</strong> colecciones existentes (--drop).
                      <br><span class="text-xs text-slate-500">
                        Sin marcar = mezclar (puede duplicar si hay _id repetidos).
                      </span>
                    </span>
                  </label>
                  <label class="block text-sm mb-1">
                    Escribe <code class="bg-slate-100 px-1 rounded">RESTAURAR</code> para confirmar:
                  </label>
                  <input name="confirm" required
                         class="w-full rounded border-slate-300 text-sm mb-4" />
                  <div class="flex justify-end gap-2">
                    <button type="button" @click="open = false"
                            class="rounded border px-3 py-2 text-sm">Cancelar</button>
                    <button class="rounded bg-amber-600 text-white px-3 py-2 text-sm hover:bg-amber-700">
                      Restaurar ahora
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
