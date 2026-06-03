<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\DeclarationPdfService;
use App\Services\InstanceDiscovery;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportController extends Controller
{
    public function __construct(
        private InstanceDiscovery $discovery,
        private ReportService $reports,
        private DeclarationPdfService $pdfService,
        private AuditService $audit,
    ) {}

    public function index(string $slug)
    {
        $instance = $this->resolveInstance($slug);
        return view('instances.reports.index', compact('instance'));
    }

    /** Endpoint AJAX — devuelve JSON para el preview dinámico. */
    public function preview(Request $request, string $slug)
    {
        $instance = $this->resolveInstance($slug);
        [$from, $to] = $this->parseDates($request);

        try {
            $rows = $this->reports->getRows($slug, $from, $to);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $firmadas  = count(array_filter($rows, fn($r) => $r['firmada']));
        $completas = count(array_filter($rows, fn($r) => $r['declaracionCompleta']));

        return response()->json([
            'rows'      => $rows,
            'total'     => count($rows),
            'firmadas'  => $firmadas,
            'completas' => $completas,
        ]);
    }

    /** Descarga el informe como archivo .xlsx */
    public function exportExcel(Request $request, string $slug)
    {
        $instance = $this->resolveInstance($slug);
        [$from, $to] = $this->parseDates($request);
        $rows = $this->reports->getRows($slug, $from, $to);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Declaraciones');

        // Encabezados
        $headers = [
            'A' => '#',
            'B' => 'Declarante',
            'C' => 'CURP',
            'D' => 'RFC',
            'E' => 'Usuario',
            'F' => 'Tipo de declaración',
            'G' => 'Año de ejercicio',
            'H' => 'Firmada',
            'I' => 'Completa',
            'J' => 'Fecha de presentación',
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue("{$col}1", $label);
        }

        // Estilo de encabezados
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E293B'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
        ]);

        // Datos
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValue("B{$r}", $row['nombre']);
            $sheet->setCellValue("C{$r}", $row['curp']);
            $sheet->setCellValue("D{$r}", $row['rfc']);
            $sheet->setCellValue("E{$r}", $row['username']);
            $sheet->setCellValue("F{$r}", $row['tipoDeclaracion']);
            $sheet->setCellValue("G{$r}", $row['anioEjercicio']);
            $sheet->setCellValue("H{$r}", $row['firmada'] ? 'Sí' : 'No');
            $sheet->setCellValue("I{$r}", $row['declaracionCompleta'] ? 'Sí' : 'No');
            $sheet->setCellValue("J{$r}", $row['createdAt']);

            // Fila par = fondo suave
            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:J{$r}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
                ]);
            }
        }

        // Ancho automático
        foreach (array_keys($headers) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Fila de resumen al final
        $lastRow = count($rows) + 3;
        $firmadas  = count(array_filter($rows, fn($r) => $r['firmada']));
        $completas = count(array_filter($rows, fn($r) => $r['declaracionCompleta']));
        $sheet->setCellValue("A{$lastRow}", "Total: " . count($rows) . " · Firmadas: {$firmadas} · Completas: {$completas}");
        $sheet->getStyle("A{$lastRow}")->getFont()->setItalic(true)->setColor(
            (new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'))
        );
        $sheet->mergeCells("A{$lastRow}:J{$lastRow}");

        $writer   = new Xlsx($spreadsheet);
        $filename = "declaraciones_{$slug}_{$from->format('Ymd')}_{$to->format('Ymd')}.xlsx";

        $this->audit->log('report.excel', [
            'instance_slug' => $slug,
            'details'       => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => count($rows)],
        ]);

        $dl = $request->input('dl');
        $response = response()->streamDownload(
            fn() => $writer->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
        if ($dl) {
            $response->cookie('dl_ready', $dl, 0, '/', null, false, false);
        }
        return $response;
    }

    /** Descarga el PDF de una sola declaración. */
    public function downloadPdf(Request $request, string $slug, string $declaracionId)
    {
        $this->resolveInstance($slug);

        try {
            $pdf = $this->pdfService->getPdf($slug, $declaracionId);
        } catch (\Throwable $e) {
            abort(500, $e->getMessage());
        }

        $this->audit->log('report.pdf', [
            'instance_slug' => $slug,
            'target_type'   => 'declaracion',
            'target_id'     => $declaracionId,
        ]);

        $dl = $request->input('dl');
        $response = response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"declaracion_{$declaracionId}.pdf\"",
        ]);
        if ($dl) {
            $response->cookie('dl_ready', $dl, 0, '/', null, false, false);
        }
        return $response;
    }

    /** Descarga un ZIP con los PDFs de todas las declaraciones del rango. */
    public function downloadZip(Request $request, string $slug)
    {
        $this->resolveInstance($slug);
        [$from, $to] = $this->parseDates($request);

        $rows = $this->reports->getRows($slug, $from, $to);

        if (empty($rows)) {
            abort(404, 'No hay declaraciones en el rango seleccionado.');
        }

        // Límite de seguridad para no agotar memoria/tiempo
        if (count($rows) > 300) {
            abort(422, 'El rango contiene más de 300 declaraciones. Acota las fechas para descargar el ZIP.');
        }

        try {
            $zipPath = $this->pdfService->buildZip($slug, $rows);
        } catch (\Throwable $e) {
            abort(500, $e->getMessage());
        }

        $filename = "declaraciones_{$slug}_{$from->format('Ymd')}_{$to->format('Ymd')}.zip";

        $this->audit->log('report.zip', [
            'instance_slug' => $slug,
            'details'       => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => count($rows)],
        ]);

        $dl = $request->input('dl');
        $response = response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
        if ($dl) {
            $response->cookie('dl_ready', $dl, 0, '/', null, false, false);
        }
        return $response;
    }

    /** Vista HTML optimizada para impresión / Guardar como PDF. */
    public function exportPrint(Request $request, string $slug)
    {
        $instance = $this->resolveInstance($slug);
        [$from, $to] = $this->parseDates($request);
        $rows = $this->reports->getRows($slug, $from, $to);

        $firmadas  = count(array_filter($rows, fn($r) => $r['firmada']));
        $completas = count(array_filter($rows, fn($r) => $r['declaracionCompleta']));

        return view('instances.reports.print', compact(
            'instance', 'rows', 'from', 'to', 'firmadas', 'completas'
        ));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function resolveInstance(string $slug): array
    {
        $inst = $this->discovery->find($slug);
        if (! $inst) {
            throw new NotFoundHttpException("Instancia '$slug' no encontrada.");
        }
        if (! auth()->user()->canDownloadReportsFor($slug)) {
            abort(403);
        }
        return $inst;
    }

    private function parseDates(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfMonth();

        return [$from, $to];
    }
}
