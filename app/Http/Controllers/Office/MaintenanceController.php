<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\CompanySetting;
use App\Models\MaintenanceJob;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\Store;
use App\Models\Vehicle;
use App\Services\Closing\MonthCloseService;
use App\Services\Maintenance\MaintenanceAnalysis;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\MaintenanceController ( /office/maintenance )
//  Location: app/Http/Controllers/Office/MaintenanceController.php
//
//  The maintenance log, one month of history at a time:
//    tiles   jobs still open, services due or overdue, this month's
//            jobs and their cost
//    tabs    Open · Due · Log
//  An open job holds the truck (it cannot be booked). The cost is
//  not a trip expense and not a G&A line.
//  Permissions: maintenance.view / create / edit / delete.
// ══════════════════════════════════════════════════════════════════

class MaintenanceController extends Controller
{
    use RunsTripRules;

    public const TABS = ['open', 'due', 'log'];

    public function index(Request $request, MaintenanceAnalysis $analysis, MonthCloseService $months): Response
    {
        $user = $request->user('web');
        $month = MonthCloseService::parseMonth($request->query('month'));
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'open';
        $vehicleId = $request->integer('vehicle') ?: null;
        $screen = $analysis->screen($month, $vehicleId, $user->company_id);

        return Inertia::render('Office/Maintenance/Index', [
            'month' => $month->format('Y-m'),
            'months' => $months->months(),
            'tab' => $tab,
            'vehicle' => $vehicleId,
            'totals' => $screen['totals'],
            'openJobs' => $screen['open'],
            'due' => $screen['due'],
            'log' => $screen['log'],
            'vehicles' => $this->vehicles(),
            'workshop' => (bool) CompanySetting::for($user->company_id)->has_workshop,
            'stores' => Store::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'stock' => StockItem::query()->orderBy('name')->get(['id', 'name', 'unit'])->map(function (StockItem $item) {
                $qty = StockBalance::query()->where('stock_item_id', $item->id)->get(['store_id', 'qty', 'value']);

                return [
                    'id' => $item->id, 'name' => $item->name, 'unit' => $item->unit,
                    'places' => $qty->map(fn (StockBalance $b) => [
                        'store_id' => $b->store_id, 'qty' => $b->qty, 'average' => $b->qty > 0 ? round($b->average(), 2) : 0,
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    public function store(Request $request, MaintenanceService $service): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(function () use ($service, $data, $request) {
            $service->record($data, $request->user('web'));
        }, __('maintenance.ok.saved'));
    }

    public function update(Request $request, MaintenanceJob $job, MaintenanceService $service): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(function () use ($service, $job, $data, $request) {
            $service->update($job, $data, $request->user('web'));
        }, __('maintenance.ok.saved'));
    }

    public function destroy(Request $request, MaintenanceJob $job, MaintenanceService $service): RedirectResponse
    {
        $service->delete($job, $request->user('web'));

        return back()->with('success', __('maintenance.ok.deleted'));
    }

    private function validated(Request $request): array
    {
        $companyId = $request->user('web')->company_id;

        return $request->validate([
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('company_id', $companyId)],
            'kind' => ['required', Rule::in(MaintenanceJob::KINDS)],
            'category' => ['required', Rule::in(MaintenanceJob::CATEGORIES)],
            'status' => ['required', Rule::in(MaintenanceJob::STATUSES)],
            'opened_at' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateTimeString()],
            'completed_at' => ['nullable', 'date'],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'workshop' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:250'],
            'interval_km' => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'source' => ['nullable', Rule::in(MaintenanceJob::SOURCES)],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('company_id', $companyId)],
            'lines' => ['nullable', 'array'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);
    }

    /** @return list<array{id:int, number:string, letters:string, odometer:?int}> */
    private function vehicles(): array
    {
        return Vehicle::query()->orderBy('plate_number')->get(['id', 'plate_number', 'plate_letters', 'odometer_km'])
            ->map(fn (Vehicle $v) => [
                'id' => $v->id, 'number' => $v->plate_number, 'letters' => $v->plate_letters, 'odometer' => $v->odometer_km,
            ])->values()->all();
    }
}
