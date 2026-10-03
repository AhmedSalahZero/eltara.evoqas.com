<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\CompanySetting;
use App\Models\Driver;
use App\Models\FuelEntry;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Closing\MonthCloseService;
use App\Services\Fuel\FuelAnalysis;
use App\Services\Fuel\FuelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\FuelController ( /office/fuel )
//  Location: app/Http/Controllers/Office/FuelController.php
//
//  Fuel log (Scope §6.10), one month at a time:
//    tiles   litres, cost, fleet average km/L, share paid by the
//            company fuel card, trucks flagged for possible over-draw
//    tabs    Refuels · Per truck (km/L against the truck's standard) ·
//            Waiting for litres (fuel spent on trips that has no
//            litres / odometer yet)
//    add / change / delete a refuel (App\Services\Fuel\FuelService)
//  Permissions: fuel.view / create / edit / delete.
// ══════════════════════════════════════════════════════════════════

class FuelController extends Controller
{
    use RunsTripRules;

    public const TABS = ['refuels', 'trucks', 'waiting'];

    public function index(Request $request, FuelAnalysis $analysis, MonthCloseService $months): Response
    {
        $user = $request->user('web');
        $month = MonthCloseService::parseMonth($request->query('month'));
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'refuels';
        $vehicleId = $request->integer('vehicle') ?: null;

        $result = $analysis->analyse($month->copy()->startOfMonth(), $month->copy()->endOfMonth(), $user->company_id, $vehicleId);
        $waiting = $analysis->waiting();

        return Inertia::render('Office/Fuel/Index', [
            'month'    => $month->format('Y-m'),
            'months'   => $months->months(),
            'tab'      => $tab,
            'vehicle'  => $vehicleId,
            'totals'   => $result['totals'] + ['waiting' => count($waiting)],
            'trucks'   => $result['trucks'],
            'entries'  => $result['entries'],
            'waiting'  => $waiting,
            'options'  => fn () => $user->can('fuel.create') ? $this->formOptions($user->company_id) : null,
            'vehicles' => Vehicle::query()->where('ownership', 'own')->orderBy('plate_number')->get(['id', 'plate_number', 'plate_letters'])
                ->map(fn (Vehicle $v) => ['id' => $v->id, 'number' => $v->plate_number, 'letters' => $v->plate_letters])->values(),
        ]);
    }

    public function store(Request $request, FuelService $service): RedirectResponse
    {
        $data = $this->validated($request, true);

        return $this->attempt(function () use ($service, $data, $request) {
            $service->record($data, $request->user('web'));
        }, __('finance.ok.fuel_saved'));
    }

    public function update(Request $request, FuelEntry $entry, FuelService $service): RedirectResponse
    {
        $data = $this->validated($request, false);

        return $this->attempt(function () use ($service, $entry, $data, $request) {
            $service->update($entry, $data, $request->user('web'));
        }, __('finance.ok.fuel_saved'));
    }

    public function destroy(Request $request, FuelEntry $entry, FuelService $service): RedirectResponse
    {
        $service->delete($entry, $request->user('web'));

        return back()->with('success', __('finance.ok.fuel_deleted'));
    }

    private function validated(Request $request, bool $creating): array
    {
        $companyId = $request->user('web')->company_id;
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        $rules = [
            'filled_at'       => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateTimeString()],
            'litres'          => ['nullable', 'numeric', 'min:0.01', 'max:'.FuelService::MAX_LITRES],
            'price_per_litre' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'amount'          => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
            'odometer_km'     => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'station'         => ['nullable', 'string', 'max:120'],
            'note'            => ['nullable', 'string', 'max:250'],
        ];

        if ($creating) {
            $rules += [
                'vehicle_id'      => ['nullable', 'integer', $exists('vehicles')],
                'driver_id'       => ['nullable', 'integer', $exists('drivers')],
                'trip_id'         => ['nullable', 'integer', $exists('trips')],
                'trip_expense_id' => ['nullable', 'integer', $exists('trip_expenses')],
                'paid_by'         => ['required', Rule::in(FuelEntry::PAID_BY)],
            ];
        }

        return $request->validate($rules);
    }

    /** What the refuel form needs: own trucks, drivers, open trips, the diesel price. */
    private function formOptions(int $companyId): array
    {
        return [
            'vehicles' => Vehicle::query()->where('ownership', 'own')->with('driver:id,name')->orderBy('plate_number')->get()
                ->map(fn (Vehicle $v) => [
                    'id' => $v->id, 'number' => $v->plate_number, 'letters' => $v->plate_letters, 'driver_id' => $v->driver_id,
                    'driver' => $v->driver?->name, 'odometer' => $v->odometer_km, 'standard' => $v->std_km_per_litre,
                ])->values(),
            'drivers'  => Driver::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Driver $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'trips'    => Trip::query()->where('is_hired', false)->whereIn('status', ['accepted', 'loading', 'on_road', 'delivered'])
                ->orderByDesc('loading_at')->limit(200)->get(['id', 'number', 'vehicle_id', 'driver_id', 'status'])
                ->map(fn (Trip $t) => ['id' => $t->id, 'number' => $t->number, 'vehicle_id' => $t->vehicle_id, 'driver_id' => $t->driver_id, 'status' => $t->status])->values(),
            'diesel'   => (float) CompanySetting::for($companyId)->diesel_price,
        ];
    }
}
