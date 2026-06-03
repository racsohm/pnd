<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Informe de declaraciones — {{ $instance['name'] }}</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      font-size: 11px;
      color: #1e293b;
      padding: 24px 32px;
    }

    .toolbar {
      display: flex;
      gap: 8px;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid #e2e8f0;
    }
    .btn-print {
      background: #1e293b; color: #fff; border: none;
      padding: 8px 18px; border-radius: 6px; cursor: pointer; font-size: 13px;
    }
    .btn-print:hover { background: #334155; }
    .btn-close {
      background: #fff; border: 1px solid #cbd5e1;
      padding: 8px 18px; border-radius: 6px; cursor: pointer; font-size: 13px;
    }
    .btn-close:hover { background: #f8fafc; }

    h1 { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
    .meta { font-size: 11px; color: #64748b; margin-bottom: 16px; }

    .stats {
      display: flex;
      gap: 0;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 16px;
    }
    .stat {
      flex: 1;
      text-align: center;
      padding: 12px 8px;
      border-right: 1px solid #e2e8f0;
    }
    .stat:last-child { border-right: none; }
    .stat-value { font-size: 24px; font-weight: 700; line-height: 1; }
    .stat-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-top: 4px; }

    table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
    thead tr { background: #1e293b; color: #fff; }
    th { padding: 6px 8px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
    td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    tbody tr:nth-child(even) td { background: #f8fafc; }
    tbody tr:hover td { background: #f1f5f9; }

    .mono { font-family: 'Courier New', Courier, monospace; font-size: 9.5px; }

    .badge {
      display: inline-block;
      padding: 1px 7px;
      border-radius: 99px;
      font-size: 9.5px;
      font-weight: 600;
    }
    .badge-si  { background: #d1fae5; color: #065f46; }
    .badge-no  { background: #fef3c7; color: #92400e; }
    .badge-nc  { background: #f1f5f9; color: #64748b; }

    .empty { text-align: center; padding: 40px; color: #94a3b8; }

    @media print {
      @page { margin: 1.2cm 1.5cm; size: landscape; }
      body { padding: 0; font-size: 9.5px; }
      .toolbar { display: none !important; }
      table { font-size: 9px; }
      th { font-size: 8.5px; }
      td { padding: 4px 6px; }
      h1 { font-size: 14px; }
    }
  </style>
</head>
<body>

  <div class="toolbar">
    <button class="btn-print" onclick="window.print()">Imprimir / Guardar PDF</button>
    <button class="btn-close" onclick="window.close()">Cerrar</button>
  </div>

  <h1>Informe de declaraciones &mdash; {{ $instance['name'] }}</h1>
  <p class="meta">
    Período: <strong>{{ $from->format('d/m/Y') }}</strong> al <strong>{{ $to->format('d/m/Y') }}</strong>
    &nbsp;&middot;&nbsp;
    Generado: {{ now()->format('d/m/Y H:i') }}
  </p>

  <div class="stats">
    <div class="stat">
      <div class="stat-value">{{ count($rows) }}</div>
      <div class="stat-label">Total</div>
    </div>
    <div class="stat">
      <div class="stat-value" style="color:#059669">{{ $firmadas }}</div>
      <div class="stat-label">Firmadas</div>
    </div>
    <div class="stat">
      <div class="stat-value" style="color:#d97706">{{ count($rows) - $firmadas }}</div>
      <div class="stat-label">Pendientes firma</div>
    </div>
    <div class="stat">
      <div class="stat-value" style="color:#2563eb">{{ $completas }}</div>
      <div class="stat-label">Completas</div>
    </div>
  </div>

  @if (empty($rows))
    <p class="empty">No hay declaraciones en este período.</p>
  @else
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Declarante</th>
          <th>CURP</th>
          <th>RFC</th>
          <th>Tipo</th>
          <th>Año</th>
          <th>Firmada</th>
          <th>Completa</th>
          <th>Fecha de presentación</th>
        </tr>
      </thead>
      <tbody>
        @php
          $tipoMap = ['INICIAL' => 'Inicial', 'MODIFICACION' => 'Modificación', 'CONCLUSION' => 'Conclusión'];
        @endphp
        @foreach ($rows as $i => $row)
          <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $row['nombre'] }}</td>
            <td class="mono">{{ $row['curp'] }}</td>
            <td class="mono">{{ $row['rfc'] }}</td>
            <td>{{ $tipoMap[$row['tipoDeclaracion']] ?? $row['tipoDeclaracion'] }}</td>
            <td>{{ $row['anioEjercicio'] }}</td>
            <td>
              <span class="badge {{ $row['firmada'] ? 'badge-si' : 'badge-no' }}">
                {{ $row['firmada'] ? 'Sí' : 'No' }}
              </span>
            </td>
            <td>
              <span class="badge {{ $row['declaracionCompleta'] ? 'badge-si' : 'badge-nc' }}">
                {{ $row['declaracionCompleta'] ? 'Sí' : 'No' }}
              </span>
            </td>
            <td>{{ $row['createdAt'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

</body>
</html>
