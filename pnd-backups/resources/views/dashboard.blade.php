@extends('layouts.app')

@section('content')
<div class="flex items-center justify-between mb-5">
  <h1 class="text-2xl font-semibold">Instancias detectadas</h1>
</div>

@if (count($instances) === 0)
  <div class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-slate-600">
    <p class="text-center mb-4">
      No se detectaron instancias en
      <code class="bg-slate-100 px-1 rounded">{{ config('backups.instances_path') }}</code>.
    </p>

    @if ($diagnostics)
      <div class="max-w-xl mx-auto text-sm space-y-2 bg-slate-50 border border-slate-200 rounded p-4">
        <div class="font-medium text-slate-700">Diagnóstico:</div>
        <ul class="space-y-1">
          <li>Path en contenedor: <code>{{ $diagnostics['base_path'] }}</code></li>
          <li>
            ¿Existe?
            <span class="{{ $diagnostics['base_exists'] ? 'text-emerald-700' : 'text-rose-600' }}">
              {{ $diagnostics['base_exists'] ? 'sí' : 'no — el volumen no está montado o el path es incorrecto' }}
            </span>
          </li>
          @if ($diagnostics['base_exists'])
            <li>
              ¿Legible?
              <span class="{{ $diagnostics['base_readable'] ? 'text-emerald-700' : 'text-rose-600' }}">
                {{ $diagnostics['base_readable'] ? 'sí' : 'no — revisar permisos del directorio en el host' }}
              </span>
            </li>
            <li>Subdirectorios encontrados: <strong>{{ $diagnostics['subdirs'] }}</strong></li>
          @endif
        </ul>

        @if (! empty($diagnostics['rejected']))
          <div class="pt-2">
            <div class="font-medium text-slate-700 mb-1">Subdirectorios descartados:</div>
            <ul class="list-disc list-inside text-rose-700 space-y-0.5">
              @foreach ($diagnostics['rejected'] as $slug => $reason)
                <li><code>{{ $slug }}</code>: {{ $reason }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        @if ($diagnostics['base_exists'] && $diagnostics['subdirs'] === 0)
          <p class="pt-2 text-slate-500">
            El path está montado pero está vacío. Verificá que en el host
            <code>INSTANCES_HOST_PATH</code> apunte al directorio padre de las
            instancias (donde viven los <code>SistemaDeclaraciones_backend/</code>).
          </p>
        @endif
      </div>
    @endif
  </div>
@else
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($instances as $i)
      @php $s = $stats[$i['slug']] ?? ['count' => 0, 'latest' => null]; @endphp
      <a href="{{ route('instances.show', $i['slug']) }}"
         class="block rounded-lg bg-white border hover:border-slate-400 hover:shadow-sm p-4 transition">
        <div class="font-medium text-lg truncate">{{ $i['name'] }}</div>
        <div class="text-xs text-slate-500 mb-3 truncate">DB: {{ $i['mongo_db'] }}</div>
        <div class="text-sm text-slate-700">
          <div>Respaldos: <strong>{{ $s['count'] }}</strong></div>
          @if ($s['latest'])
            <div class="text-xs text-slate-500 mt-1">
              Último: {{ $s['latest']->created_at->diffForHumans() }}
              ({{ $s['latest']->humanSize() }})
            </div>
          @else
            <div class="text-xs text-slate-400 mt-1">Sin respaldos todavía</div>
          @endif
        </div>
      </a>
    @endforeach
  </div>
@endif
@endsection
