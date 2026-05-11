@extends('layouts.app')

@section('content')
<a href="{{ route('instances.show', $instance['slug']) }}" class="text-sm text-slate-500 hover:underline">← {{ $instance['name'] }}</a>

<div class="mt-2 mb-5">
  <h1 class="text-2xl font-semibold">Inspector</h1>
  <p class="text-sm text-slate-500">
    Lectura directa sobre <code>{{ $instance['mongo_db'] }}</code> ·
    Solo permite consultar; no modifica datos.
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
        @endphp
        <details class="px-4 py-3 group" @if ($i === 0 && $q !== '') open @endif>
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
            <span class="ml-auto text-xs text-slate-400">
              <span class="group-open:hidden">▸ ver doc</span>
              <span class="hidden group-open:inline">▾ ocultar</span>
            </span>
          </summary>
          <pre class="mt-2 text-xs bg-slate-50 border rounded p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
      @endforeach
    </div>
  @endif
</div>
@endsection
