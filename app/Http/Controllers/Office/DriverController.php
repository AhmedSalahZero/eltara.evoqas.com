<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Trips\TripFigures;
use App\Services\Trips\WalletLedger;
use App\Support\Audit;
use App\Support\DocumentExpiry;
use App\Support\EgyptPhone;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\DriverController ( /office/drivers )
//  Location: app/Http/Controllers/Office/DriverController.php
//
//  Drivers (Scope §6.8) — each one is also a Driver App account:
//    index   → list: mobile, vehicle, pay basis, licence status, last
//              app sync, trips this month and cash held (Step 3)
//    show    → the driver file: his three wallets (+ own-pocket owed)
//              and latest trips with budget vs actual (Step 3)
//    store   → new driver + his first 4-digit PIN (typed or generated),
//              inside the company's driver-accounts limit
//    update  → details, licence, pay basis, usual vehicle
//    toggle  → suspend / reactivate the app account (reactivating
//              needs a free place under the limit)
//    resetPin→ a new PIN, shown ONCE to the office user to pass on
//    destroy → deletes a driver with no history
//  The PIN is stored scrambled (hashed): nobody can read it later,
//  which is why it is shown only once.
//  Permissions: drivers.view / create / edit / delete.
// ══════════════════════════════════════════════════════════════════

class DriverController extends Controller
{
    public function index(Request $request, WalletLedger $ledger): Response
    {
        $search = trim((string) $request->query('search'));
        $filter = $request->query('filter');
        $soon = today()->addDays(DocumentExpiry::alertDays())->toDateString();

        $drivers = Driver::query()
            ->with('vehicle:id,driver_id,plate_number,plate_letters')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', '%'.(EgyptPhone::normalize($search) ?? $search).'%')))
            ->when($filter === 'suspended', fn ($q) => $q->where('is_active', false))
            ->when($filter === 'licence', fn ($q) => $q->whereDate('license_expires_at', '<=', $soon))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $company = $request->user('web')->company;

        // Step 3: trips this month and cash held, for this page in two queries.
        $ids = $drivers->getCollection()->pluck('id')->all();
        $balances = $ledger->manyDriverBalances($ids);
        $monthTrips = Trip::query()->whereIn('driver_id', $ids ?: [0])->where('status', '!=', 'cancelled')
            ->where('loading_at', '>=', now()->startOfMonth())->selectRaw('driver_id, count(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id');

        return Inertia::render('Office/Drivers/Index', [
            'drivers' => [
                'data'  => $drivers->getCollection()->map(fn (Driver $d) => $this->row($d) + [
                    'month_trips' => (int) ($monthTrips[$d->id] ?? 0),
                    'held'        => round(($balances[$d->id]['custody'] ?? 0) + ($balances[$d->id]['collections'] ?? 0), 2),
                    'advances'    => $balances[$d->id]['advances'] ?? 0,
                ])->values(),
                'links' => $drivers->linkCollection(),
                'total' => $drivers->total(),
            ],
            'counts' => [
                'all'       => Driver::query()->count(),
                'suspended' => Driver::query()->where('is_active', false)->count(),
                'licence'   => Driver::query()->whereDate('license_expires_at', '<=', $soon)->count(),
            ],
            'limits'  => ['used' => $company->driverSeatsUsed(), 'limit' => $company->driver_accounts_limit],
            'filters' => ['search' => $search, 'filter' => $filter],
            'options' => $this->formOptions(),
        ]);
    }

    public function show(Driver $driver, WalletLedger $ledger): Response
    {
        $driver->load(['vehicle:id,driver_id,plate_number,plate_letters,vehicle_type_id,model', 'vehicle.vehicleType']);

        $trips = TripFigures::withMoney(Trip::query()->select('trips.*')->where('driver_id', $driver->id))
            ->with(['route:id,origin_ar,origin_en,destination_ar,destination_en', 'customer:id,name_ar,name_en'])
            ->orderByDesc('loading_at')->limit(10)->get();
        $cash = \App\Models\WalletEntry::query()->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])->whereIn('wallet', ['custody', 'collections'])
            ->selectRaw('trip_id, SUM(amount) as total')->groupBy('trip_id')->pluck('total', 'trip_id');

        return Inertia::render('Office/Drivers/Show', [
            'driver'  => $this->row($driver) + [
                'vehicle_type'  => $driver->vehicle?->vehicleType?->displayName(),
                'vehicle_model' => $driver->vehicle?->model,
            ],
            'wallets' => $ledger->driverBalances($driver->id) + [
                'open_trips' => Trip::query()->open()->where('driver_id', $driver->id)->count(),
            ],
            'trips'   => $trips->map(function (Trip $t) use ($cash) {
                $standard = round((float) array_sum($t->standard_budget ?? []), 2);
                $cost = round((float) $t->cost_total, 2);

                return [
                    'id'         => $t->id,
                    'number'     => $t->number,
                    'status'     => $t->status,
                    'route'      => $t->route?->displayName(),
                    'customer'   => $t->customer?->displayName(),
                    'loading_at' => $t->loading_at?->toIso8601String(),
                    'standard'   => $standard,
                    'cost'       => $cost,
                    'variance'   => round($cost - $standard, 2),
                    'held'       => $t->isOpen() ? round((float) ($cash[$t->id] ?? 0), 2) : null,
                ];
            })->values(),
            'options' => $this->formOptions($driver),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $request->user('web')->company;

        if (! $company->hasFreeDriverSeat()) {
            return back()->with('error', __('errors.driver_limit_reached', ['limit' => $company->driver_accounts_limit]));
        }

        $data = $this->validated($request);
        $pin = $request->filled('pin') ? $request->input('pin') : self::randomPin();

        $driver = DB::transaction(function () use ($data, $pin, $request) {
            $vehicleId = $data['vehicle_id'] ?? null;
            unset($data['vehicle_id']);

            $driver = Driver::query()->create($data + ['pin' => $pin, 'is_active' => true, 'created_by' => $request->user('web')->id]);
            $this->assignVehicle($driver, $vehicleId);
            Audit::record('driver.created', $driver, ['after' => ['name' => $driver->name, 'mobile' => $driver->mobile]]);

            return $driver;
        });

        return redirect()->route('office.drivers.show', $driver)
            ->with('success', __('common.driver_saved', ['name' => $driver->name]))
            ->with('pin', ['name' => $driver->name, 'mobile' => $driver->mobile, 'pin' => $pin]);
    }

    public function update(Request $request, Driver $driver): RedirectResponse
    {
        $data = $this->validated($request, $driver);

        DB::transaction(function () use ($data, $driver) {
            $vehicleId = $data['vehicle_id'] ?? null;
            unset($data['vehicle_id']);

            $driver->fill($data);
            $changes = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $driver->getDirty());
            $driver->save();
            $this->assignVehicle($driver, $vehicleId);

            if ($changes) {
                Audit::record('driver.updated', $driver, ['after' => $changes]);
            }
        });

        return back()->with('success', __('common.driver_saved', ['name' => $driver->name]));
    }

    public function toggle(Request $request, Driver $driver): RedirectResponse
    {
        $company = $request->user('web')->company;

        if (! $driver->is_active && ! $company->hasFreeDriverSeat()) {
            return back()->with('error', __('errors.driver_limit_reached', ['limit' => $company->driver_accounts_limit]));
        }

        $driver->forceFill(['is_active' => ! $driver->is_active])->save();
        Audit::record($driver->is_active ? 'driver.reactivated' : 'driver.suspended', $driver);

        return back()->with('success', __($driver->is_active ? 'common.user_reactivated' : 'common.user_suspended', ['name' => $driver->name]));
    }

    public function resetPin(Driver $driver): RedirectResponse
    {
        $pin = self::randomPin();
        $driver->forceFill(['pin' => $pin])->save();
        Audit::record('driver.pin_reset', $driver);

        return back()->with('pin', ['name' => $driver->name, 'mobile' => $driver->mobile, 'pin' => $pin]);
    }

    public function destroy(Driver $driver): RedirectResponse
    {
        try {
            DB::transaction(function () use ($driver) {
                Audit::record('driver.deleted', $driver, ['before' => ['name' => $driver->name, 'mobile' => $driver->mobile]]);
                Vehicle::query()->where('driver_id', $driver->id)->update(['driver_id' => null]);
                $driver->delete();
            });
        } catch (QueryException) {
            return back()->with('error', __('errors.in_use_cannot_delete'));
        }

        return redirect()->route('office.drivers.index')->with('success', __('common.deleted'));
    }

    // ── Helpers ────────────────────────────────────────────────────

    public static function randomPin(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function validated(Request $request, ?Driver $driver = null): array
    {
        $request->merge([
            'mobile' => EgyptPhone::normalize($request->input('mobile')) ?? $request->input('mobile'),
            'pin'    => $request->filled('pin') ? EgyptPhone::digits((string) $request->input('pin')) : null,
        ]);

        $companyId = $request->user('web')->company_id;

        $data = $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'mobile'             => ['required', 'regex:'.EgyptPhone::PATTERN, Rule::unique('drivers', 'mobile')->ignore($driver?->id)],
            // The first PIN is set when the driver is created; later only "New PIN" changes it.
            'pin'                => $driver ? ['exclude'] : ['nullable', 'digits:4'],
            'license_number'     => ['nullable', 'string', 'max:40'],
            'license_expires_at' => ['nullable', 'date'],
            'pay_basis'          => ['required', Rule::in(Driver::PAY_BASES)],
            'base_salary'        => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'joined_at'          => ['nullable', 'date'],
            'vehicle_id'         => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('company_id', $companyId)],
            'notes'              => ['nullable', 'string', 'max:2000'],
        ], ['mobile.unique' => __('errors.phone_taken')]);

        unset($data['pin']);

        return $data;
    }

    /** Makes $vehicleId this driver's usual truck (and frees his previous one). */
    private function assignVehicle(Driver $driver, ?int $vehicleId): void
    {
        Vehicle::query()->where('driver_id', $driver->id)->when($vehicleId, fn ($q) => $q->whereKeyNot($vehicleId))->update(['driver_id' => null]);

        if ($vehicleId) {
            Vehicle::query()->whereKey($vehicleId)->update(['driver_id' => $driver->id]);
        }
    }

    private function row(Driver $d): array
    {
        return [
            'id'                 => $d->id,
            'name'               => $d->name,
            'initials'           => $d->initials(),
            'mobile'             => $d->mobile,
            'license_number'     => $d->license_number,
            'license_expires_at' => $d->license_expires_at?->toDateString(),
            'licence'            => DocumentExpiry::describe($d->license_expires_at),
            'pay_basis'          => $d->pay_basis,
            'base_salary'        => $d->base_salary,
            'joined_at'          => $d->joined_at?->toDateString(),
            'notes'              => $d->notes,
            'is_active'          => $d->is_active,
            'last_login_at'      => $d->last_login_at?->toIso8601String(),
            'last_sync_at'       => $d->last_sync_at?->toIso8601String(),
            'vehicle_id'         => $d->vehicle?->id,
            'vehicle'            => $d->vehicle ? ['number' => $d->vehicle->plate_number, 'letters' => $d->vehicle->plate_letters] : null,
        ];
    }

    private function formOptions(?Driver $current = null): array
    {
        return [
            'vehicles' => Vehicle::query()->where('ownership', 'own')->with('driver:id,name')->orderBy('plate_number')
                ->get(['id', 'plate_number', 'plate_letters', 'driver_id'])
                ->map(fn (Vehicle $v) => [
                    'id'    => $v->id,
                    'plate' => $v->plateText(),
                    'taken' => $v->driver_id && $v->driver_id !== $current?->id ? $v->driver?->name : null,
                ])->values(),
        ];
    }
}
