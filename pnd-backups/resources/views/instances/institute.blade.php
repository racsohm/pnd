@extends('layouts.app')

@section('content')
<a href="{{ route('instances.show', $instance['slug']) }}" class="text-sm text-slate-500 hover:underline">← {{ $instance['name'] }}</a>

<h1 class="text-2xl font-semibold mt-2 mb-1">Datos de la institución</h1>
<p class="text-sm text-slate-500 mb-5">
  Modifica los campos que el asistente preguntó al crear la instancia. Se escriben en
  <code class="text-xs">SistemaDeclaraciones_backend/src/data/instituciones.json</code>.
  Para que los cambios tomen efecto en el sistema, hay que reconstruir el backend.
</p>

@if ($error)
  <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
    {{ $error }}
  </div>
@endif

@if ($errors->any())
  <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
    <ul class="list-disc list-inside">
      @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
    </ul>
  </div>
@endif

<form method="POST" action="{{ route('instances.institute.update', $instance['slug']) }}"
      class="rounded-lg border bg-white p-5 mb-6 space-y-4">
  @csrf @method('PUT')

  <div>
    <label class="block text-sm font-medium mb-1">Nombre oficial del ayuntamiento / institución</label>
    <input type="text" name="ente_publico" required maxlength="300"
           value="{{ old('ente_publico', $fields['ente_publico']) }}"
           class="w-full rounded border-slate-300 text-sm" />
  </div>

  <div class="grid gap-4 md:grid-cols-2">
    <div>
      <label class="block text-sm font-medium mb-1">Clave corta (prefijo en documentos)</label>
      <input type="text" name="clave" required maxlength="60"
             value="{{ old('clave', $fields['clave']) }}"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Ciudad o municipio</label>
      <input type="text" name="lugar" required maxlength="200"
             value="{{ old('lugar', $fields['lugar']) }}"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
  </div>

  <div class="pt-2 border-t">
    <p class="text-sm font-semibold mb-2 text-slate-700">Titular que firma y recibe las declaraciones</p>
    <div class="grid gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium mb-1">Nombre completo del titular</label>
        <input type="text" name="nombre" required maxlength="200"
               value="{{ old('nombre', $fields['nombre']) }}"
               class="w-full rounded border-slate-300 text-sm" />
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Cargo del titular</label>
        <input type="text" name="cargo" required maxlength="200"
               value="{{ old('cargo', $fields['cargo']) }}"
               class="w-full rounded border-slate-300 text-sm" />
      </div>
    </div>
  </div>

  <label class="flex items-start gap-2 text-sm pt-2 border-t">
    <input type="checkbox" name="rebuild" value="1" checked class="mt-1 rounded">
    <span>
      <strong>Reconstruir y reiniciar el backend</strong> con estos cambios.
      <br><span class="text-xs text-slate-500">
        Equivale a <code>docker compose up --build -d app</code> en el directorio de la instancia.
        El frontend sigue sirviendo durante el rebuild (puede tardar varios minutos).
      </span>
    </span>
  </label>

  <div class="pt-2">
    <button class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
      Guardar cambios
    </button>
  </div>
</form>

<form method="POST" action="{{ route('instances.rebuild', $instance['slug']) }}"
      class="mb-6 flex items-center gap-3"
      onsubmit="return confirm('¿Reconstruir el backend sin cambios en el JSON?')">
  @csrf
  <button class="rounded border px-3 py-2 text-sm hover:bg-slate-50" type="submit">
    Solo reconstruir
  </button>
  <span class="text-xs text-slate-500">
    Útil si ya editaste el JSON a mano o cambiaste algo en el backend y querés rebuildear.
  </span>
</form>

<div class="rounded-lg border bg-white">
  <div class="px-4 py-3 border-b flex items-center justify-between">
    <span class="font-semibold">Log de rebuild</span>
    <span id="rebuild-status" class="text-xs">
      @if ($running)
        <span class="inline-flex items-center gap-1 text-amber-700">
          <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> en curso…
        </span>
      @elseif ($log !== '')
        <span class="text-slate-500">última corrida</span>
      @else
        <span class="text-slate-400">sin actividad</span>
      @endif
    </span>
  </div>
  <pre id="rebuild-log"
       class="m-0 p-4 text-xs leading-relaxed text-slate-700 bg-slate-50 max-h-96 overflow-auto whitespace-pre-wrap">{{ $log !== '' ? $log : 'No hay rebuilds previos.' }}</pre>
</div>

<script>
(function () {
  const url = @json(route('instances.rebuild.log', $instance['slug']));
  const initiallyRunning = @json($running);
  const pre = document.getElementById('rebuild-log');
  const status = document.getElementById('rebuild-status');
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

  if (initiallyRunning) {
    pre.scrollTop = pre.scrollHeight;
    timer = setInterval(tick, 3000);
  }
})();
</script>
@endsection
