<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Support\Audit;
use App\Services\Reports\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\ReportController ( /office/reports )   Scope §6.14
//  Location: app/Http/Controllers/Office/ReportController.php
//
//  index   the hub: 12 report cards, as on the demo's Reports screen
//  show    one report on screen, with its filters (period, customer,
//          truck, driver)
//  export  the same report as an Excel file (every row)
//  print   the same report as a printable page (Save as PDF)
//  The numbers come from App\Services\Reports\ReportService, so the
//  three always agree. Permission: reports.view.
// ══════════════════════════════════════════════════════════════════

class ReportController extends Controller
{
    /** Rows sent to the screen; the Excel file and the print page have all of them. */
    private const SCREEN_ROWS = 500;

    public function __construct(private readonly ReportService $reports) {}

    public function index(): Response
    {
        return Inertia::render('Office/Reports/Index', [
            'items' => collect(ReportService::KEYS)->map(fn ($k) => [
                'key' => $k, 'icon' => ReportService::ICONS[$k], 'title' => __('reports.items.'.$k.'.title'), 'desc' => __('reports.items.'.$k.'.desc'),
            ])->all(),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $filters = ReportService::filters($request->query());
        $data = $this->reports->build($report, $request->user('web')->company_id, $filters);

        $total = count($data['rows']);
        $data['rows'] = array_slice($data['rows'], 0, self::SCREEN_ROWS);

        return Inertia::render('Office/Reports/Show', [
            'report'  => $data,
            'total_rows' => $total,
            'screen_limit' => self::SCREEN_ROWS,
            'options' => [
                'customers' => Customer::query()->orderBy('name_ar')->get(['id', 'name_ar', 'name_en'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->displayName()])->all(),
                'vehicles'  => Vehicle::query()->orderBy('plate_number')->get(['id', 'plate_number', 'plate_letters'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->plateText()])->all(),
                'drivers'   => Driver::query()->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->all(),
            ],
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $user = $request->user('web')->loadMissing('company');
        $data = $this->reports->build($report, $user->company_id, ReportService::filters($request->query()));
        $ar = app()->getLocale() === 'ar';

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $data['title']), 0, 31));
        $sheet->setRightToLeft($ar);

        $sheet->setCellValue('A1', $data['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', __('reports.period').': '.$data['filters']['from'].' → '.$data['filters']['to']);

        $headRow = 4;
        foreach ($data['columns'] as $i => $c) {
            $sheet->setCellValue([$i + 1, $headRow], $c['label']);
        }
        $last = Coordinate::stringFromColumnIndex(max(1, count($data['columns'])));
        $sheet->getStyle("A{$headRow}:{$last}{$headRow}")->applyFromArray([
            'font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FC']],
        ]);

        $format = ['money' => '#,##0', 'dec' => '#,##0.00', 'num' => '#,##0', 'pct' => '0.0'];
        $row = $headRow + 1;
        foreach ($data['rows'] as $r) {
            foreach ($data['columns'] as $i => $c) {
                $sheet->setCellValue([$i + 1, $row], $r[$c['key']] ?? null);
                if (isset($format[$c['type']])) {
                    $sheet->getStyle([$i + 1, $row])->getNumberFormat()->setFormatCode($format[$c['type']]);
                }
            }
            $row++;
        }

        if ($data['totals']) {
            $sheet->setCellValue([1, $row], __('reports.total'));
            foreach ($data['columns'] as $i => $c) {
                if (array_key_exists($c['key'], $data['totals'])) {
                    $sheet->setCellValue([$i + 1, $row], $data['totals'][$c['key']]);
                    $sheet->getStyle([$i + 1, $row])->getNumberFormat()->setFormatCode($format[$c['type']] ?? 'General');
                }
            }
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
            $row++;
        }
        if ($data['note']) {
            $sheet->setCellValue([1, $row + 1], $data['note']);
        }
        if ($data['truncated']) {
            // The file must never look complete when it is not.
            $sheet->setCellValue([1, $row + 3], __('reports.truncated'));
            $sheet->getStyle([1, $row + 3])->getFont()->setBold(true)->getColor()->setRGB('C0392B');
        }

        foreach (range(1, max(1, count($data['columns']))) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
            $sheet->getStyle([$i, $headRow, $i, $row])->getAlignment()->setHorizontal($ar ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT);
        }

        Audit::record('report.exported', null, ['report' => $report, 'format' => 'xlsx', 'filters' => $data['filters']]);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'),
            'el-tara-'.str_replace('_', '-', $report).'-'.now()->format('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function print(Request $request, string $report): View
    {
        $user = $request->user('web')->loadMissing('company');
        $data = $this->reports->build($report, $user->company_id, ReportService::filters($request->query()));

        Audit::record('report.exported', null, ['report' => $report, 'format' => 'pdf', 'filters' => $data['filters']]);

        return view('office.report-print', ['company' => $user->company->displayName(), 'data' => $data, 'locale' => app()->getLocale(), 'by' => $user->name]);
    }
}
