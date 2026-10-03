<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\Driver;
use App\Models\DriverAdvance;
use App\Services\Advances\AdvanceService;
use App\Services\Closing\MonthCloseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\AdvanceController ( /office/advances )
//  Location: app/Http/Controllers/Office/AdvanceController.php
//
//  Driver advances (Scope §6.12):
//    tiles   still owed by drivers, drivers with an advance, the
//            payroll deduction of the month, what is already deducted
//    tabs    Open advances · Payroll deduction (the monthly total
//            for the payroll sheet, with an Excel file) · Closed
//    actions give an advance · change instalment / reason · record a
//            cash repayment · cancel a manual advance · apply the
//            month's deductions
//  Advances created automatically from personal spending on a trip
//  are marked "from trip".
//  Permissions: driver_advances.view / create / edit / approve
//  (apply the payroll deductions) / delete (cancel).
// ══════════════════════════════════════════════════════════════════

class AdvanceController extends Controller
{
    use RunsTripRules;

    public const TABS = ['open', 'payroll', 'closed'];

    public function index(Request $request, AdvanceService $service, MonthCloseService $months): Response
    {
        $user = $request->user('web');
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'open';
        $month = AdvanceService::parseMonth($request->query('month'));
        $payroll = $service->payroll($month);

        $open = DriverAdvance::query()->where('status', 'open');

        $list = DriverAdvance::query()
            ->with(['driver:id,name,mobile', 'trip:id,number'])
            ->when($tab === 'closed', fn ($q) => $q->whereIn('status', ['repaid', 'cancelled'])->orderByDesc('id')->limit(200))
            ->when($tab !== 'closed', fn ($q) => $q->where('status', 'open')->orderBy('driver_id')->orderBy('id'))
            ->get();

        return Inertia::render('Office/Advances/Index', [
            'tab'      => $tab,
            'month'    => $month->format('Y-m'),
            'months'   => $months->months(),
            'tiles'    => [
                'owed'      => round((float) (clone $open)->selectRaw('COALESCE(SUM(amount - repaid_amount), 0) as t')->value('t'), 2),
                'drivers'   => (int) (clone $open)->distinct()->count('driver_id'),
                'open'      => (int) (clone $open)->count(),
                'due'       => round(array_sum(array_column($payroll, 'due')), 2),
                'applied'   => round(array_sum(array_column($payroll, 'applied')), 2),
                'no_instalment' => (int) (clone $open)->whereNull('monthly_instalment')->count(),
            ],
            'advances' => $list->map(fn (DriverAdvance $a) => [
                'id'          => $a->id,
                'driver'      => ['id' => $a->driver_id, 'name' => $a->driver?->name],
                'reason'      => $a->reason,
                'source'      => $a->source,
                'trip'        => $a->trip ? ['id' => $a->trip->id, 'number' => $a->trip->number] : null,
                'amount'      => $a->amount,
                'instalment'  => $a->monthly_instalment,
                'repaid'      => $a->repaid_amount,
                'remaining'   => $a->remaining(),
                'status'      => $a->status,
                'created_at'  => $a->created_at?->toIso8601String(),
                'can_cancel'  => $a->status === 'open' && $a->source === 'manual' && (float) $a->repaid_amount <= 0,
                'next'        => $a->status === 'open' ? $service->instalmentDue($a) : null,
            ])->values(),
            'payroll'  => $payroll,
            'options'  => fn () => $user->can('driver_advances.create')
                ? ['drivers' => Driver::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'base_salary'])
                    ->map(fn (Driver $d) => ['id' => $d->id, 'name' => $d->name, 'salary' => $d->base_salary])->values()]
                : null,
        ]);
    }

    public function store(Request $request, AdvanceService $service): RedirectResponse
    {
        $companyId = $request->user('web')->company_id;
        $data = $request->validate([
            'driver_id'          => ['required', 'integer', Rule::exists('drivers', 'id')->where('company_id', $companyId)],
            'amount'             => ['required', 'numeric', 'min:0.01', 'max:'.AdvanceService::MAX_AMOUNT],
            'reason'             => ['nullable', 'string', 'max:250'],
            'monthly_instalment' => ['nullable', 'numeric', 'min:0', 'max:'.AdvanceService::MAX_AMOUNT],
        ]);

        return $this->attempt(function () use ($service, $data, $request) {
            $driver = Driver::query()->findOrFail($data['driver_id']);
            $service->create($driver, (float) $data['amount'], $data['reason'] ?? null, isset($data['monthly_instalment']) ? (float) $data['monthly_instalment'] : null, $request->user('web'));
        }, __('finance.ok.advance_saved'));
    }

    public function update(Request $request, DriverAdvance $advance, AdvanceService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason'             => ['nullable', 'string', 'max:250'],
            'monthly_instalment' => ['nullable', 'numeric', 'min:0', 'max:'.AdvanceService::MAX_AMOUNT],
        ]);

        return $this->attempt(function () use ($service, $advance, $data, $request) {
            $service->update($advance, $data['reason'] ?? null, isset($data['monthly_instalment']) ? (float) $data['monthly_instalment'] : null, $request->user('web'));
        }, __('finance.ok.advance_saved'));
    }

    public function repay(Request $request, DriverAdvance $advance, AdvanceService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.AdvanceService::MAX_AMOUNT],
            'note'   => ['nullable', 'string', 'max:250'],
        ]);

        return $this->attempt(function () use ($service, $advance, $data, $request) {
            $service->repayCash($advance, (float) $data['amount'], $data['note'] ?? null, $request->user('web'));
        }, __('finance.ok.repayment_saved'));
    }

    public function cancel(Request $request, DriverAdvance $advance, AdvanceService $service): RedirectResponse
    {
        return $this->attempt(function () use ($service, $advance, $request) {
            $service->cancel($advance, $request->user('web'));
        }, __('finance.ok.advance_cancelled'));
    }

    /** Records the month's payroll deductions (each advance once). */
    public function applyPayroll(Request $request, AdvanceService $service): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $month = AdvanceService::parseMonth($data['month']);

        return $this->attempt(function () use ($service, $month, $request) {
            $done = $service->applyPayroll($month, $request->user('web'));

            return back()->with($done['count'] > 0 ? 'success' : 'error', $done['count'] > 0
                ? __('finance.ok.payroll_applied', ['n' => $done['count'], 'total' => number_format($done['total'], 2)])
                : __('finance.adv.nothing_to_deduct'));
        });
    }

    /** The payroll sheet of the month as an Excel file. */
    public function export(Request $request, AdvanceService $service): StreamedResponse
    {
        $month = AdvanceService::parseMonth($request->query('month'));
        $rows = $service->payroll($month);
        $ar = app()->getLocale() === 'ar';

        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setRightToLeft($ar);
        $sheet->setTitle($ar ? 'خصم السلف' : 'Advance deductions');
        $sheet->fromArray($ar
            ? ['السائق', 'الموبايل', 'الراتب الأساسي', 'خصم السلف عن '.$month->format('Y-m'), 'تم خصمه', 'المتبقي بعد الخصم']
            : ['Driver', 'Mobile', 'Base salary', 'Advance deduction '.$month->format('Y-m'), 'Already deducted', 'Left after deduction'], null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([$row['name'], $row['mobile'], $row['base_salary'], $row['due'], $row['applied'], $row['remaining_after']], null, "A{$r}");
            $r++;
        }
        $sheet->setCellValue("A{$r}", $ar ? 'الإجمالي' : 'Total');
        $sheet->setCellValue("D{$r}", array_sum(array_column($rows, 'due')));
        $sheet->setCellValue("E{$r}", array_sum(array_column($rows, 'applied')));
        $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
        $sheet->getStyle("C2:F{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $name = 'el-tara-advances-'.$month->format('Y-m').'.xlsx';

        return response()->streamDownload(function () use ($sheet) {
            (new Xlsx($sheet->getParent()))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
