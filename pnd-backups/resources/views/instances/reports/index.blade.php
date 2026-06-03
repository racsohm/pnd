@extends('layouts.app')

@section('content')
<a href="{{ route('instances.show', $instance['slug']) }}"
   class="text-sm text-slate-500 hover:underline">← {{ $instance['name'] }}</a>

<div class="flex items-center justify-between mt-2 mb-6">
  <h1 class="text-2xl font-semibold">Informe de declaraciones</h1>
</div>

{{-- Filtro de fechas --}}
<div class="rounded-lg border bg-white p-4 mb-4">
  <div class="flex flex-wrap items-end gap-4">
    <div>
      <label class="block text-xs font-medium text-slate-600 mb-1">Desde</label>
      <input type="date" id="filter-from"
             class="rounded border-slate-300 text-sm" />
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-600 mb-1">Hasta</label>
      <input type="date" id="filter-to"
             class="rounded border-slate-300 text-sm" />
    </div>
    <button id="btn-filter"
            class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
      Filtrar
    </button>
    <span id="loading-indicator" class="hidden text-sm text-slate-400">Consultando…</span>
  </div>
</div>

{{-- Tarjetas de resumen --}}
<div id="summary" class="grid grid-cols-3 gap-4 mb-4" style="display:none!important">
  <div class="rounded-lg border bg-white p-4 text-center">
    <div id="stat-total" class="text-3xl font-semibold">—</div>
    <div class="text-xs uppercase tracking-wide text-slate-500 mt-1">Total</div>
  </div>
  <div class="rounded-lg border bg-white p-4 text-center">
    <div id="stat-firmadas" class="text-3xl font-semibold text-emerald-700">—</div>
    <div class="text-xs uppercase tracking-wide text-slate-500 mt-1">Firmadas</div>
  </div>
  <div class="rounded-lg border bg-white p-4 text-center">
    <div id="stat-pendientes" class="text-3xl font-semibold text-amber-700">—</div>
    <div class="text-xs uppercase tracking-wide text-slate-500 mt-1">Pendientes</div>
  </div>
</div>

{{-- Tabla de resultados --}}
<div class="rounded-lg border bg-white">
  <div class="px-4 py-3 border-b font-semibold text-sm flex items-center justify-between">
    <span>Resultados</span>
    <div id="export-buttons" style="display:none" class="flex gap-2">
      <button id="btn-excel" type="button"
              class="rounded border px-3 py-1.5 text-xs hover:bg-slate-50 whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
        ↓ Excel (.xlsx)
      </button>
      <button id="btn-zip" type="button"
              class="rounded border px-3 py-1.5 text-xs hover:bg-slate-50 whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
        ↓ ZIP (todos los PDFs)
      </button>
      <a id="btn-pdf" href="#" target="_blank"
         class="rounded border px-3 py-1.5 text-xs hover:bg-slate-50 whitespace-nowrap">
        ↓ Imprimir / PDF
      </a>
    </div>
  </div>
  <div id="results-body">
    <div class="p-8 text-sm text-slate-400 text-center">
      Selecciona un rango y presiona <strong>Filtrar</strong>.
    </div>
  </div>
</div>

<script>
(function () {
  // ── Fechas por defecto: mes actual ─────────────────────────────
  const pad  = n => String(n).padStart(2, '0');
  const fmt  = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
  const now  = new Date();
  const from = new Date(now.getFullYear(), now.getMonth(), 1);
  const to   = new Date(now.getFullYear(), now.getMonth() + 1, 0);

  const elFrom    = document.getElementById('filter-from');
  const elTo      = document.getElementById('filter-to');
  const btnFilter = document.getElementById('btn-filter');
  const loader    = document.getElementById('loading-indicator');
  const summary   = document.getElementById('summary');
  const results   = document.getElementById('results-body');
  const exports   = document.getElementById('export-buttons');
  const btnExcel  = document.getElementById('btn-excel');
  const btnZip    = document.getElementById('btn-zip');
  const btnPdf    = document.getElementById('btn-pdf');

  const PREVIEW_URL = @json(route('instances.reports.preview', $instance['slug']));
  const EXCEL_URL   = @json(route('instances.reports.excel',   $instance['slug']));
  const PRINT_URL   = @json(route('instances.reports.print',   $instance['slug']));
  const ZIP_URL     = @json(route('instances.reports.zip',     $instance['slug']));
  const PDF_BASE    = @json(url("instances/{$instance['slug']}/declaraciones"));

  // URLs base (sin fechas) — se sobreescriben en fetchReport
  let excelUrl = '#';
  let zipUrl   = '#';

  elFrom.value = fmt(from);
  elTo.value   = fmt(to);

  // ── Helpers ────────────────────────────────────────────────────
  function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '—');
    return d.innerHTML;
  }

  function tipoLabel(t) {
    return { INICIAL: 'Inicial', MODIFICACION: 'Modificación', CONCLUSION: 'Conclusión' }[t] ?? t;
  }

  function badge(val, yesColor, noColor) {
    const [cls, label] = val
      ? [`bg-${yesColor}-100 text-${yesColor}-700`, 'Sí']
      : [`bg-${noColor}-100 text-${noColor}-700`, 'No'];
    return `<span class="inline-block rounded px-2 py-0.5 text-xs ${cls}">${label}</span>`;
  }

  // ── Fetch y render ─────────────────────────────────────────────
  async function fetchReport() {
    const dateFrom = elFrom.value;
    const dateTo   = elTo.value;
    if (!dateFrom || !dateTo) return;

    loader.classList.remove('hidden');
    btnFilter.disabled = true;
    summary.style.display = 'none';
    exports.style.display  = 'none';
    results.innerHTML = '<div class="p-8 text-sm text-slate-400 text-center">Consultando MongoDB…</div>';

    try {
      const res  = await fetch(`${PREVIEW_URL}?from=${dateFrom}&to=${dateTo}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await res.json();

      if (data.error) {
        results.innerHTML = `<div class="p-6 text-sm text-rose-600 text-center">${esc(data.error)}</div>`;
        return;
      }

      // Estadísticas
      document.getElementById('stat-total').textContent     = data.total;
      document.getElementById('stat-firmadas').textContent  = data.firmadas;
      document.getElementById('stat-pendientes').textContent = data.total - data.firmadas;
      summary.style.removeProperty('display');

      // Guardar URLs con fechas para los botones de descarga
      excelUrl = `${EXCEL_URL}?from=${dateFrom}&to=${dateTo}`;
      zipUrl   = `${ZIP_URL}?from=${dateFrom}&to=${dateTo}`;
      btnPdf.href = `${PRINT_URL}?from=${dateFrom}&to=${dateTo}`;
      exports.style.display = 'flex';

      // Conectar listeners de descarga con feedback (cada vez que cambia el rango)
      btnExcel.onclick = function () {
        downloadWithFeedback({
          url: excelUrl,
          trigger: btnExcel,
          loadingText: 'Generando Excel…',
          idleText: '↓ Excel (.xlsx)',
        });
      };
      btnZip.onclick = function () {
        downloadWithFeedback({
          url: zipUrl,
          trigger: btnZip,
          loadingText: 'Empaquetando PDFs… (puede tardar varios minutos)',
          idleText: '↓ ZIP (todos los PDFs)',
        });
      };

      if (data.rows.length === 0) {
        results.innerHTML = '<div class="p-8 text-sm text-slate-400 text-center">No hay declaraciones en este rango de fechas.</div>';
        return;
      }

      // Tabla
      let html = `
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b text-left text-xs uppercase text-slate-500 bg-slate-50">
              <tr>
                <th class="px-4 py-2 w-8">#</th>
                <th class="px-4 py-2">Declarante</th>
                <th class="px-4 py-2">CURP</th>
                <th class="px-4 py-2">RFC</th>
                <th class="px-4 py-2">Tipo</th>
                <th class="px-4 py-2">Año</th>
                <th class="px-4 py-2">Firmada</th>
                <th class="px-4 py-2">Completa</th>
                <th class="px-4 py-2">Fecha</th>
                <th class="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody>`;

      data.rows.forEach((row, i) => {
        const pdfUrl = `${PDF_BASE}/${row._id}/pdf`;
        html += `
          <tr class="border-b last:border-0 hover:bg-slate-50">
            <td class="px-4 py-2 text-slate-400 text-xs">${i + 1}</td>
            <td class="px-4 py-2 font-medium">${esc(row.nombre)}</td>
            <td class="px-4 py-2 font-mono text-xs">${esc(row.curp)}</td>
            <td class="px-4 py-2 font-mono text-xs">${esc(row.rfc)}</td>
            <td class="px-4 py-2">${esc(tipoLabel(row.tipoDeclaracion))}</td>
            <td class="px-4 py-2">${esc(row.anioEjercicio)}</td>
            <td class="px-4 py-2">${badge(row.firmada,    'emerald', 'amber')}</td>
            <td class="px-4 py-2">${badge(row.declaracionCompleta, 'emerald', 'slate')}</td>
            <td class="px-4 py-2 text-slate-500 text-xs">${esc(row.createdAt)}</td>
            <td class="px-4 py-2">
              <a href="${pdfUrl}" data-pdf-download
                 class="text-xs text-slate-600 hover:underline whitespace-nowrap">↓ PDF</a>
            </td>
          </tr>`;
      });

      html += `</tbody></table></div>`;
      results.innerHTML = html;

    } catch (e) {
      results.innerHTML = '<div class="p-6 text-sm text-rose-600 text-center">Error de conexión con el servidor.</div>';
    } finally {
      loader.classList.add('hidden');
      btnFilter.disabled = false;
    }
  }

  // Delegación de eventos para PDFs individuales en la tabla
  results.addEventListener('click', function (e) {
    const link = e.target.closest('a[data-pdf-download]');
    if (!link) return;
    e.preventDefault();
    downloadWithFeedback({
      url: link.href,
      trigger: link,
      loadingText: '…',
      idleText: '↓ PDF',
    });
  });

  btnFilter.addEventListener('click', fetchReport);

  // Carga automática al abrir la página
  fetchReport();
}());
</script>
@endsection
