@extends('layouts.app')

@section('content')
<div class="flex items-center justify-between mb-5">
  <h1 class="text-2xl font-semibold">Instancias detectadas</h1>
</div>

@if (count($instances) === 0)
  <div class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
    No se detectaron instancias en
    <code class="bg-slate-100 px-1 rounded">{{ config('backups.instances_path') }}</code>.
    <br>Verifica que el volumen <code>INSTANCES_HOST_PATH</code> esté montado correctamente.
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
