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
  <div class="flex items-center gap-2">
    @if (auth()->user()->canManageUsersFor($instance['slug']))
      <a href="{{ route('instances.members.index', $instance['slug']) }}"
         class="rounded border px-3 py-2 text-sm hover:bg-slate-50">
        Usuarios →
      </a>
    @endif
    @if (auth()->user()->canDownloadReportsFor($instance['slug']))
      <a href="{{ route('instances.reports.index', $instance['slug']) }}"
         class="rounded border px-3 py-2 text-sm hover:bg-slate-50">
        Informes →
      </a>
    @endif
    @if (auth()->user()->isSuperAdmin())
      <a href="{{ route('instances.institute', $instance['slug']) }}"
         class="rounded border px-3 py-2 text-sm hover:bg-slate-50">
        Datos de institución →
      </a>
      <a href="{{ route('instances.mail', $instance['slug']) }}"
         class="rounded border px-3 py-2 text-sm hover:bg-slate-50">
        Correo →
      </a>
    @endif
    @if (auth()->user()->isSuperAdmin())
      <a href="{{ route('instances.inspect', $instance['slug']) }}"
         class="rounded border px-3 py-2 text-sm hover:bg-slate-50">
        Inspector →
      </a>
    @endif
  </div>
</div>

@php
  // Extrae el timestamp embebido en el ObjectId (_id) y devuelve una
  // fecha "hace X" legible. Si _id no es ObjectId (es string), cae a '—'.
  $whenFromId = function ($doc) {
      $id = data_get($doc, '_id.$oid');
      if (! $id || ! preg_match('/^[a-f0-9]{24}$/i', $id)) return null;
      return \Carbon\Carbon::createFromTimestamp(hexdec(substr($id, 0, 8)));
  };
@endphp

<section class="mb-6">
  <h2 class="text-sm font-semibold text-slate-700 mb-2">Actividad</h2>

  @if ($statsError)
    <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
      No se pudo leer Mongo: {{ $statsError }}
    </div>
  @elseif ($stats)
    <div class="grid gap-4 md:grid-cols-2">
      @foreach ($stats as $collName => $s)
        <div class="rounded-lg border bg-white p-4">
          <div class="flex items-baseline justify-between">
            <span class="text-sm font-semibold capitalize">{{ $collName }}</span>
            <a href="{{ route('instances.inspect', ['slug' => $instance['slug'], 'collection' => $collName]) }}"
               class="text-xs text-slate-500 hover:underline">ver →</a>
          </div>
          <div class="mt-2 grid grid-cols-3 gap-2 text-center">
            <div>
              <div class="text-2xl font-semibold">{{ number_format($s['total']) }}</div>
              <div class="text-[11px] uppercase tracking-wide text-slate-500">Total</div>
            </div>
            <div>
              <div class="text-2xl font-semibold text-emerald-700">+{{ number_format($s['last_7']) }}</div>
              <div class="text-[11px] uppercase tracking-wide text-slate-500">7 días</div>
            </div>
            <div>
              <div class="text-2xl font-semibold text-slate-700">+{{ number_format($s['last_30']) }}</div>
              <div class="text-[11px] uppercase tracking-wide text-slate-500">30 días</div>
            </div>
          </div>

          @if (! empty($s['latest']))
            <ul class="mt-3 pt-3 border-t divide-y text-xs">
              @foreach ($s['latest'] as $doc)
                @php
                  $label = data_get($doc, 'email')
                          ?? data_get($doc, 'username')
                          ?? data_get($doc, 'nombre')
                          ?? data_get($doc, 'name')
                          ?? data_get($doc, '_id.$oid', '—');
                  $when  = $whenFromId($doc);
                @endphp
                <li class="py-1.5 flex items-center justify-between gap-3">
                  <span class="truncate text-slate-700">{{ $label }}</span>
                  <span class="shrink-0 text-slate-400">
                    {{ $when ? $when->diffForHumans() : '—' }}
                  </span>
                </li>
              @endforeach
            </ul>
          @endif
        </div>
      @endforeach
    </div>
  @endif
</section>

<div class="grid gap-4 md:grid-cols-2 mb-6">
  <form method="POST" action="{{ route('backups.store', $instance['slug']) }}"
        x-data="{ busy: false }"
        @submit="busy = true"
        class="rounded-lg border bg-white p-4">
    @csrf
    <h2 class="font-semibold mb-1">Crear respaldo ahora</h2>
    <p class="text-xs text-slate-500 mb-3">
      Ejecuta <code>mongodump</code> contra esta instancia y guarda el .gz.
    </p>
    <button type="submit"
            :disabled="busy"
            class="rounded bg-slate-900 text-white px-3 py-2 text-sm hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2">
      <template x-if="busy">
        @include('partials.spinner')
      </template>
      <span x-text="busy ? 'Generando respaldo…' : 'Generar respaldo'">Generar respaldo</span>
    </button>
  </form>

  <form method="POST" action="{{ route('backups.upload', $instance['slug']) }}"
        enctype="multipart/form-data"
        x-data="{ busy: false }"
        @submit="busy = true"
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
    <button type="submit"
            :disabled="busy"
            class="rounded bg-slate-700 text-white px-3 py-2 text-sm hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2">
      <template x-if="busy">
        @include('partials.spinner')
      </template>
      <span x-text="busy ? 'Subiendo…' : 'Subir'">Subir</span>
    </button>
  </form>
</div>

<div class="rounded-lg border bg-white mb-6">
  <div class="px-4 py-3 border-b flex items-center justify-between">
    <div>
      <span class="font-semibold">Reconstruir frontend</span>
      <p class="text-xs text-slate-500 mt-0.5">
        Ejecuta <code>docker compose build --no-cache webapp</code> y reinicia el contenedor.
        Necesario cuando se cambia <code>environment.prod.ts</code> u otras configs compiladas en Angular.
      </p>
    </div>
    <form method="POST" action="{{ route('instances.rebuild.frontend', $instance['slug']) }}"
          onsubmit="return confirm('¿Reconstruir el frontend? Puede tardar varios minutos.')">
      @csrf
      <button class="rounded border px-3 py-2 text-sm hover:bg-slate-50 whitespace-nowrap" type="submit">
        Rebuild frontend
      </button>
    </form>
  </div>

  <div class="px-4 py-3 border-b flex items-center justify-between">
    <span class="text-sm font-medium text-slate-600">Log</span>
    <span id="frontend-rebuild-status" class="text-xs">
      @if ($frontendRunning)
        <span class="inline-flex items-center gap-1 text-amber-700">
          <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> en curso…
        </span>
      @elseif ($frontendLog !== '')
        <span class="text-slate-500">última corrida</span>
      @else
        <span class="text-slate-400">sin actividad</span>
      @endif
    </span>
  </div>
  <pre id="frontend-rebuild-log"
       class="m-0 p-4 text-xs leading-relaxed text-slate-700 bg-slate-50 max-h-72 overflow-auto whitespace-pre-wrap">{{ $frontendLog !== '' ? $frontendLog : 'No hay rebuilds previos.' }}</pre>
</div>

<script>
(function () {
  const url = @json(route('instances.rebuild.frontend.log', $instance['slug']));
  const pre = document.getElementById('frontend-rebuild-log');
  const status = document.getElementById('frontend-rebuild-status');
  let timer = null;

  function setRunning(running) {
    if (running) {
      status.innerHTML = '<span class="inline-flex items-center gap-1 text-amber-700"><span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> en curso…</span>';
    } else {
      status.innerHTML = '<span class="text-emerald-700">terminado</span>';
    }
  }

  async function tick() {
    try {
      const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (! r.ok) return;
      const data = await r.json();
      if (data.log && data.log !== pre.textContent) {
        const atBottom = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20;
        pre.textContent = data.log;
        if (atBottom) pre.scrollTop = pre.scrollHeight;
      }
      if (! data.running) {
        setRunning(false);
        clearInterval(timer);
      }
    } catch (e) { /* ignorar errores de red intermitentes */ }
  }

  if (@json($frontendRunning)) {
    pre.scrollTop = pre.scrollHeight;
    timer = setInterval(tick, 3000);
  }
})();
</script>

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
          <tr class="border-b last:border-0 hover:bg-slate-50" x-data="{ open: false, dlBusy: false }">
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
                 :class="dlBusy ? 'opacity-50 pointer-events-none' : ''"
                 class="text-slate-700 hover:underline text-sm"
                 @click.prevent="
                   dlBusy = true;
                   downloadWithFeedback({
                     url: $el.href,
                     trigger: $el,
                     loadingText: 'Descargando…',
                     idleText: 'Descargar',
                     onDone: () => dlBusy = false,
                   });
                 ">
                <span x-show="!dlBusy">Descargar</span>
                <span x-show="dlBusy" x-cloak>Descargando…</span>
              </a>
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
                      x-data="{ busy: false }"
                      @submit="busy = true"
                      @click.away="if(!busy) open = false"
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
                    <button type="button" @click="if(!busy) open = false"
                            class="rounded border px-3 py-2 text-sm">Cancelar</button>
                    <button type="submit"
                            :disabled="busy"
                            class="rounded bg-amber-600 text-white px-3 py-2 text-sm hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2">
                      <template x-if="busy">
                        @include('partials.spinner')
                      </template>
                      <span x-text="busy ? 'Restaurando…' : 'Restaurar ahora'">Restaurar ahora</span>
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
