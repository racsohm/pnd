@extends('layouts.app')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold">Log de auditoría</h1>
  <span class="text-sm text-slate-500">{{ $logs->total() }} {{ $logs->total() === 1 ? 'registro' : 'registros' }}</span>
</div>

{{-- Filtros --}}
<form method="GET" action="{{ route('audit.index') }}"
      class="rounded-lg border bg-white p-4 mb-4">
  <div class="flex flex-wrap items-end gap-3">
    <div>
      <label class="block text-xs font-medium text-slate-600 mb-1">Desde</label>
      <input type="date" name="from" value="{{ request('from') }}"
             class="rounded border-slate-300 text-sm" />
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600 mb-1">Hasta</label>
      <input type="date" name="to" value="{{ request('to') }}"
             class="rounded border-slate-300 text-sm" />
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600 mb-1">Acción</label>
      <select name="action" class="rounded border-slate-300 text-sm">
        <option value="">Todas</option>
        @foreach ($actions as $a)
          <option value="{{ $a }}" @selected(request('action') === $a)>
            {{ \App\Models\AuditLog::actionLabel($a) }}
          </option>
        @endforeach
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600 mb-1">Instancia</label>
      <select name="instance" class="rounded border-slate-300 text-sm">
        <option value="">Todas</option>
        @foreach ($instances as $inst)
          <option value="{{ $inst['slug'] }}" @selected(request('instance') === $inst['slug'])>
            {{ $inst['name'] }}
          </option>
        @endforeach
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600 mb-1">Usuario (email)</label>
      <input type="text" name="user" value="{{ request('user') }}"
             placeholder="Buscar…"
             class="rounded border-slate-300 text-sm w-40" />
    </div>
    <button type="submit"
            class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
      Filtrar
    </button>
    @if (request()->hasAny(['from','to','action','instance','user']))
      <a href="{{ route('audit.index') }}"
         class="rounded border px-4 py-2 text-sm hover:bg-slate-50 text-slate-600">
        Limpiar
      </a>
    @endif
  </div>
</form>

{{-- Tabla --}}
<div class="rounded-lg border bg-white">
  @if ($logs->isEmpty())
    <div class="p-8 text-sm text-slate-400 text-center">Sin registros para los filtros seleccionados.</div>
  @else
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500 bg-slate-50">
          <tr class="border-b">
            <th class="px-4 py-2 whitespace-nowrap">Fecha</th>
            <th class="px-4 py-2">Usuario</th>
            <th class="px-4 py-2">Acción</th>
            <th class="px-4 py-2">Instancia</th>
            <th class="px-4 py-2">Objetivo</th>
            <th class="px-4 py-2">Detalles</th>
            <th class="px-4 py-2">IP</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($logs as $log)
            <tr class="border-b last:border-0 hover:bg-slate-50 align-top">
              <td class="px-4 py-2 whitespace-nowrap text-slate-500 text-xs">
                {{ $log->created_at->format('Y-m-d H:i:s') }}
              </td>
              <td class="px-4 py-2">
                <div class="font-medium text-slate-800">{{ $log->user_name ?? '—' }}</div>
                <div class="text-xs text-slate-500">{{ $log->user_email ?? '—' }}</div>
              </td>
              <td class="px-4 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs {{ \App\Models\AuditLog::actionBadgeClass($log->action) }}">
                  {{ \App\Models\AuditLog::actionLabel($log->action) }}
                </span>
              </td>
              <td class="px-4 py-2 text-slate-600 text-xs">{{ $log->instance_slug ?? '—' }}</td>
              <td class="px-4 py-2">
                @if ($log->target_name)
                  <code class="text-xs bg-slate-100 px-1 rounded">{{ $log->target_name }}</code>
                @elseif ($log->target_id)
                  <span class="text-xs font-mono text-slate-500">{{ $log->target_id }}</span>
                @else
                  <span class="text-slate-400">—</span>
                @endif
              </td>
              <td class="px-4 py-2">
                @if ($log->details)
                  <div x-data="{ open: false }">
                    <button @click="open = !open"
                            class="text-xs text-slate-500 underline underline-offset-2">
                      <span x-show="!open">ver</span>
                      <span x-show="open">ocultar</span>
                    </button>
                    <pre x-show="open" x-cloak
                         class="mt-1 text-xs bg-slate-100 p-2 rounded whitespace-pre-wrap break-all">{{ json_encode($log->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                  </div>
                @else
                  <span class="text-slate-400">—</span>
                @endif
              </td>
              <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $log->ip_address ?? '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @if ($logs->hasPages())
      <div class="px-4 py-3 border-t">
        {{ $logs->links() }}
      </div>
    @endif
  @endif
</div>
@endsection
