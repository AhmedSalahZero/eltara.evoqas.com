<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\GaEntry;
use App\Services\Closing\MonthCloseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\MonthCloseController ( /office/close )
//  Location: app/Http/Controllers/Office/MonthCloseController.php
//
//  Month close & true profit (Scope §6.13), one month at a time:
//    · the G&A lines (typed, or imported from an Excel file)
//    · the allocation flow:  total G&A ÷ own-fleet km = rate per km
//    · the month's revenue / direct profit / true profit
//    · a table of every trip that ran in the month with its km, G&A
//      share and true profit — flagging trips SPLIT between two
//      months and trips still ON THE ROAD
//    · Close the month (locks it) · Re-open it (special permission,
//      needs a reason, audited)
//  The rules are in App\Services\Closing\MonthCloseService.
//  Permissions: month_close.view / create (G&A lines) / approve
//  (close) / reopen.
// ══════════════════════════════════════════════════════════════════

class MonthCloseController extends Controller
{
    use RunsTripRules;

    public function index(Request $request, MonthCloseService $service): Response
    {
        $month = MonthCloseService::parseMonth($request->query('month'));

        return Inertia::render('Office/Close/Index', [
            'months'   => $service->months(),
            'summary'  => $service->summary($month, $request->user('web')->company_id),
            'standard' => collect(GaEntry::STANDARD)->map(fn ($names, $code) => [
                'code' => $code, 'label' => $names[app()->getLocale() === 'en' ? 1 : 0],
            ])->values(),
        ]);
    }

    public function storeLine(Request $request, MonthCloseService $service): RedirectResponse
    {
        $data = $this->validatedLine($request, true);
        $month = MonthCloseService::parseMonth($data['month']);

        return $this->attempt(function () use ($service, $month, $data, $request) {
            $service->addLine($month, $data, $request->user('web'));
        }, __('finance.ok.ga_saved'));
    }

    public function updateLine(Request $request, GaEntry $line, MonthCloseService $service): RedirectResponse
    {
        $data = $this->validatedLine($request, false);

        return $this->attempt(function () use ($service, $line, $data, $request) {
            $service->updateLine($line, $data, $request->user('web'));
        }, __('finance.ok.ga_saved'));
    }

    public function destroyLine(Request $request, GaEntry $line, MonthCloseService $service): RedirectResponse
    {
        return $this->attempt(function () use ($service, $line, $request) {
            $service->deleteLine($line, $request->user('web'));
        }, __('finance.ok.ga_deleted'));
    }

    public function import(Request $request, MonthCloseService $service): RedirectResponse
    {
        $data = $request->validate([
            'month'   => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'file'    => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048'],
            'replace' => ['nullable', 'boolean'],
        ]);
        $month = MonthCloseService::parseMonth($data['month']);

        return $this->attempt(function () use ($service, $month, $data, $request) {
            $n = $service->importLines($month, $request->file('file')->getRealPath(), (bool) ($data['replace'] ?? false), $request->user('web'));

            return back()->with('success', __('finance.ok.ga_imported', ['n' => $n]));
        });
    }

    public function close(Request $request, MonthCloseService $service): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $month = MonthCloseService::parseMonth($data['month']);

        return $this->attempt(function () use ($service, $month, $request) {
            $row = $service->close($month, $request->user('web'));

            return back()->with('success', __('finance.ok.closed', ['month' => $month->format('Y-m'), 'rate' => number_format($row->rate, 2)]));
        });
    }

    public function reopen(Request $request, MonthCloseService $service): RedirectResponse
    {
        $data = $request->validate([
            'month'  => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reason' => ['required', 'string', 'max:250'],
        ]);
        $month = MonthCloseService::parseMonth($data['month']);

        return $this->attempt(function () use ($service, $month, $data, $request) {
            $service->reopen($month, $data['reason'], $request->user('web'));

            return back()->with('success', __('finance.ok.reopened', ['month' => $month->format('Y-m')]));
        });
    }

    /** An Excel file to fill in and import: the standard G&A lines with an empty amount. */
    public function template(): StreamedResponse
    {
        $ar = app()->getLocale() === 'ar';
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setRightToLeft($ar);
        $sheet->setTitle('G&A');
        $sheet->fromArray([$ar ? 'البند' : 'Line', $ar ? 'المبلغ' : 'Amount'], null, 'A1');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $r = 2;
        foreach (GaEntry::STANDARD as [$arName, $enName]) {
            $sheet->setCellValue("A{$r}", $ar ? $arName : $enName);
            $r++;
        }
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(18);

        return response()->streamDownload(function () use ($sheet) {
            (new Xlsx($sheet->getParent()))->save('php://output');
        }, 'el-tara-ga-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** The month-close report: the figures and the per-trip allocation table. */
    public function export(Request $request, MonthCloseService $service): StreamedResponse
    {
        $month = MonthCloseService::parseMonth($request->query('month'));
        $s = $service->summary($month, $request->user('web')->company_id);
        $ar = app()->getLocale() === 'ar';

        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setRightToLeft($ar);
        $sheet->setTitle($s['month']);

        $facts = $ar
            ? [['الشهر', $s['month']], ['الحالة', __('finance.close.status.'.$s['status'])], ['إجمالي المصروفات الإدارية', $s['ga_total']], ['كم أسطول الشركة', $s['km']],
               ['معدل التحميل (جنيه/كم)', $s['rate']], ['الإيراد', $s['figures']['revenue']], ['الربح المباشر', $s['figures']['direct_profit']], ['الربح الحقيقي', $s['figures']['true_profit']]]
            : [['Month', $s['month']], ['Status', __('finance.close.status.'.$s['status'])], ['Total G&A', $s['ga_total']], ['Own-fleet km', $s['km']],
               ['Allocation rate (EGP/km)', $s['rate']], ['Revenue', $s['figures']['revenue']], ['Direct profit', $s['figures']['direct_profit']], ['True profit', $s['figures']['true_profit']]];
        $sheet->fromArray($facts, null, 'A1');
        $sheet->getStyle('A1:A8')->getFont()->setBold(true);

        $headRow = 10;
        $sheet->fromArray($ar
            ? ['الرحلة', 'الحالة', 'العميل', 'الشاحنة', 'كم الرحلة', 'كم هذا الشهر', 'مقسومة', 'على الطريق', 'حصة المصروفات الإدارية', 'الربح المباشر', 'الربح الحقيقي']
            : ['Trip', 'Status', 'Customer', 'Truck', 'Trip km', 'km this month', 'Split', 'On the road', 'G&A share', 'Direct profit', 'True profit'], null, "A{$headRow}");
        $sheet->getStyle("A{$headRow}:K{$headRow}")->getFont()->setBold(true);

        $r = $headRow + 1;
        foreach ($s['rows'] as $row) {
            $sheet->fromArray([
                $row['number'], __('trips.status.'.$row['status']), $row['customer'], $row['vehicle'] ? trim($row['vehicle']['number'].' '.$row['vehicle']['letters']) : '',
                $row['km'], $row['km_month'], $row['split'] ? ($ar ? 'نعم' : 'Yes') : '', $row['running'] ? ($ar ? 'نعم' : 'Yes') : '',
                $row['ga_share'], $row['direct'], $row['true_profit'],
            ], null, "A{$r}");
            $r++;
        }
        $sheet->getStyle('I'.($headRow + 1).":K{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($sheet) {
            (new Xlsx($sheet->getParent()))->save('php://output');
        }, 'el-tara-month-close-'.$s['month'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function validatedLine(Request $request, bool $creating): array
    {
        $rules = [
            'code'   => ['nullable', 'string', 'in:'.implode(',', array_keys(GaEntry::STANDARD))],
            'label'  => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
        ];

        if ($creating) {
            $rules['month'] = ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];
        }

        return $request->validate($rules);
    }
}
