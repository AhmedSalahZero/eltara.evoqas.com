<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Fuel\FuelAnalysis;
use App\Services\Trips\TripFigures;
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
//  El Tara — Office\VehicleController ( /office/vehicles )
//  Location: app/Http/Controllers/Office/VehicleController.php
//
//  Vehicles (Scope §6.7):
//    index   → list with search / ownership / status filters, the
//              three document badges and the "expiring within 30
//              days" alert at the top
//    show    → the vehicle file: details, documents, this month's
//              trips / revenue / direct profit per km, latest trips
//              (Step 3; fuel economy arrives with Step 6)
//    store / update → the truck form (truck type, own or hired, driver, documents)
//    destroy → deletes a vehicle that has no history
//  Permissions: vehicles.view / create / edit / delete.
//  Everything is inside the signed-in company (BelongsToCompany).
// ══════════════════════════════════════════════════════════════════

class VehicleController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));
        $filter = $request->query('filter');

        $vehicles = Vehicle::query()
            ->with(['driver:id,name', 'vehicleType'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('plate_number', 'like', "%{$search}%")
                ->orWhere('plate_letters', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")
                ->orWhere('owner_name', 'like', "%{$search}%")
                ->orWhereHas('driver', fn ($d) => $d->where('name', 'like', "%{$search}%"))))
            ->when($filter === 'own' || $filter === 'hired', fn ($q) => $q->where('ownership', $filter))
            ->when($filter === 'maintenance', fn ($q) => $q->where('status', 'maintenance'))
            ->orderBy('ownership')
            ->orderBy('plate_number')
            ->paginate(25)
            ->withQueryString();

        // "On a trip" is worked out from the trips themselves (Step 3).
        $onTrip = Trip::query()->inProgress()->whereIn('vehicle_id', $vehicles->getCollection()->pluck('id')->all() ?: [0])
            ->pluck('number', 'vehicle_id');

        return Inertia::render('Office/Vehicles/Index', [
            'vehicles' => [
                'data'  => $vehicles->getCollection()->map(fn (Vehicle $v) => $this->row($v) + ['on_trip' => $onTrip[$v->id] ?? null])->values(),
                'links' => $vehicles->linkCollection(),
                'total' => $vehicles->total(),
            ],
            'counts'   => $this->counts(),
            'expiring' => $this->expiringDocuments(),
            'filters'  => ['search' => $search, 'filter' => $filter],
            'options'  => $this->formOptions(),
        ]);
    }

    public function show(Vehicle $vehicle, FuelAnalysis $fuelAnalysis): Response
    {
        $vehicle->load(['driver:id,name,mobile', 'vehicleType']);

        $trips = TripFigures::withMoney(Trip::query()->select('trips.*')->where('vehicle_id', $vehicle->id)->where('status', '!=', 'cancelled'))
            ->with(['route:id,origin_ar,origin_en,destination_ar,destination_en', 'customer:id,name_ar,name_en', 'driver:id,name'])
            ->orderByDesc('loading_at')->limit(10)->get();

        $month = TripFigures::withMoney(Trip::query()->select('trips.*')->where('vehicle_id', $vehicle->id)
            ->where('status', '!=', 'cancelled')->where('loading_at', '>=', now()->startOfMonth()))->get();
        $revenue = round($month->sum(fn (Trip $t) => TripFigures::rowRevenue($t)), 2);
        $profit = round($revenue - $month->sum(fn (Trip $t) => (float) $t->cost_total), 2);
        $km = (int) $month->sum('km');

        // Fuel economy this month, tank to tank (Step 6) — own trucks only.
        $fuel = null;
        if ($vehicle->ownership === 'own') {
            $analysis = $fuelAnalysis->analyse(now()->startOfMonth(), now()->endOfMonth(), $vehicle->company_id, $vehicle->id);
            $truck = $analysis['trucks'][0] ?? null;
            $fuel = $truck ? [
                'kmpl' => $truck['kmpl'], 'variance' => $truck['variance'], 'flagged' => $truck['flagged'], 'fills' => $truck['fills'], 'litres' => $truck['litres'],
            ] : ['kmpl' => null, 'variance' => null, 'flagged' => false, 'fills' => 0, 'litres' => 0];
        }

        return Inertia::render('Office/Vehicles/Show', [
            'fuel' => $fuel,
            'vehicle' => $this->row($vehicle) + $vehicle->only([
                'capacity_tons', 'owner_phone', 'licence_number', 'insurance_company', 'insurance_policy_number', 'notes',
            ]) + ['driver_mobile' => $vehicle->driver?->mobile],
            'figures' => [
                'month_trips'   => $month->count(),
                'revenue'       => $revenue,
                'profit'        => $profit,
                'profit_per_km' => $km > 0 ? round($profit / $km, 2) : null,
                'km'            => $km,
            ],
            'trips'   => $trips->map(function (Trip $t) {
                $revenue = TripFigures::rowRevenue($t);
                $cost = round((float) $t->cost_total, 2);

                return [
                    'id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'route' => $t->route?->displayName(),
                    'customer' => $t->customer?->displayName(), 'driver' => $t->driver?->name, 'loading_at' => $t->loading_at?->toIso8601String(),
                    'revenue' => $revenue, 'profit' => round($revenue - $cost, 2), 'per_km' => $t->km ? round(($revenue - $cost) / $t->km, 2) : null,
                ];
            })->values(),
            'options' => $this->formOptions($vehicle),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $vehicle = DB::transaction(function () use ($data, $request) {
            $vehicle = Vehicle::query()->create($data + ['created_by' => $request->user('web')->id]);
            Audit::record('vehicle.created', $vehicle, ['after' => ['plate' => $vehicle->plateText(), 'ownership' => $vehicle->ownership]]);

            return $vehicle;
        });

        return redirect()->route('office.vehicles.show', $vehicle)->with('success', __('common.vehicle_saved', ['plate' => $vehicle->plateText()]));
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $vehicle->fill($this->validated($request, $vehicle));
        $changes = $vehicle->getDirty();
        $vehicle->save();

        if ($changes) {
            Audit::record('vehicle.updated', $vehicle, ['after' => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $changes)]);
        }

        return back()->with('success', __('common.vehicle_saved', ['plate' => $vehicle->plateText()]));
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        try {
            DB::transaction(function () use ($vehicle) {
                Audit::record('vehicle.deleted', $vehicle, ['before' => ['plate' => $vehicle->plateText()]]);
                $vehicle->delete();
            });
        } catch (QueryException) {
            return back()->with('error', __('errors.in_use_cannot_delete'));
        }

        return redirect()->route('office.vehicles.index')->with('success', __('common.deleted'));
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $request->merge([
            'plate_number'  => EgyptPhone::digits((string) $request->input('plate_number')),
            'plate_letters' => preg_replace('/\s+/u', ' ', trim((string) $request->input('plate_letters'))),
        ]);

        $companyId = $request->user('web')->company_id;

        $data = $request->validate([
            'plate_number'  => ['required', 'string', 'max:10'],
            'plate_letters' => ['required', 'string', 'max:12', Rule::unique('vehicles')
                ->where(fn ($q) => $q->where('company_id', $companyId)->where('plate_number', $request->input('plate_number')))
                ->ignore($vehicle?->id)],
            'vehicle_type_id' => ['required', 'integer', Rule::exists('vehicle_types', 'id')->where('company_id', $companyId)],
            'model'         => ['nullable', 'string', 'max:60'],
            'year'          => ['nullable', 'integer', 'min:1970', 'max:'.(now()->year + 1)],
            'capacity_tons' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'ownership'     => ['required', Rule::in(Vehicle::OWNERSHIPS)],
            'owner_name'    => ['nullable', 'required_if:ownership,hired', 'string', 'max:120'],
            'owner_phone'   => ['nullable', 'string', 'max:20'],
            'driver_id'     => ['nullable', 'integer', Rule::exists('drivers', 'id')->where('company_id', $companyId)],
            'odometer_km'   => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'std_km_per_litre' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
            'status'        => ['required', Rule::in(Vehicle::STATUSES)],
            'licence_number'          => ['nullable', 'string', 'max:40'],
            'licence_expires_at'      => ['nullable', 'date'],
            'insurance_company'       => ['nullable', 'string', 'max:80'],
            'insurance_policy_number' => ['nullable', 'string', 'max:40'],
            'insurance_expires_at'    => ['nullable', 'date'],
            'inspection_expires_at'   => ['nullable', 'date'],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ], ['plate_letters.unique' => __('errors.plate_taken')]);

        if ($data['ownership'] === 'own') {
            $data['owner_name'] = null;
            $data['owner_phone'] = null;
        }

        // A driver has one usual vehicle: moving him here frees the other one.
        if (! empty($data['driver_id'])) {
            Vehicle::query()->where('driver_id', $data['driver_id'])->when($vehicle, fn ($q) => $q->whereKeyNot($vehicle->id))->update(['driver_id' => null]);
        }

        return $data;
    }

    private function row(Vehicle $v): array
    {
        return [
            'id'               => $v->id,
            'plate_number'     => $v->plate_number,
            'plate_letters'    => $v->plate_letters,
            'vehicle_type_id'  => $v->vehicle_type_id,
            'type_name'        => $v->vehicleType?->displayName(),
            'model'            => $v->model,
            'year'             => $v->year,
            'capacity_tons'    => $v->capacity_tons,
            'ownership'        => $v->ownership,
            'owner_name'       => $v->owner_name,
            'owner_phone'      => $v->owner_phone,
            'driver_id'        => $v->driver_id,
            'driver'           => $v->driver?->name,
            'odometer_km'      => $v->odometer_km,
            'std_km_per_litre' => $v->std_km_per_litre,
            'status'           => $v->status,
            'licence_number'          => $v->licence_number,
            'licence_expires_at'      => $v->licence_expires_at?->toDateString(),
            'insurance_company'       => $v->insurance_company,
            'insurance_policy_number' => $v->insurance_policy_number,
            'insurance_expires_at'    => $v->insurance_expires_at?->toDateString(),
            'inspection_expires_at'   => $v->inspection_expires_at?->toDateString(),
            'notes'            => $v->notes,
            'documents'        => $v->documents(),
        ];
    }

    private function counts(): array
    {
        $rows = Vehicle::query()->selectRaw('ownership, status, count(*) as n')->groupBy('ownership', 'status')->get();

        return [
            'all'         => (int) $rows->sum('n'),
            'own'         => (int) $rows->where('ownership', 'own')->sum('n'),
            'hired'       => (int) $rows->where('ownership', 'hired')->sum('n'),
            'maintenance' => (int) $rows->where('status', 'maintenance')->sum('n'),
        ];
    }

    /** Vehicle documents and driving licences that are expired or end within 30 days. */
    private function expiringDocuments(): array
    {
        $limit = today()->addDays(DocumentExpiry::alertDays())->toDateString();
        $out = [];

        $vehicles = Vehicle::query()->where('ownership', 'own')->where(fn ($q) => $q
            ->whereDate('licence_expires_at', '<=', $limit)
            ->orWhereDate('insurance_expires_at', '<=', $limit)
            ->orWhereDate('inspection_expires_at', '<=', $limit))->get();

        foreach ($vehicles as $v) {
            foreach ($v->documents() as $key => $doc) {
                if (in_array($doc['state'], ['expired', 'soon'], true)) {
                    $out[] = ['kind' => 'vehicle', 'id' => $v->id, 'who' => $v->plateText(), 'document' => $key] + $doc;
                }
            }
        }

        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    private function formOptions(?Vehicle $current = null): array
    {
        return [
            // Active truck types, plus the one this truck already has (even if hidden since).
            'types'   => VehicleType::query()->ordered()
                ->where(fn ($q) => $q->where('is_active', true)->when($current?->vehicle_type_id, fn ($w) => $w->orWhere('id', $current->vehicle_type_id)))
                ->get()->map(fn (VehicleType $t) => ['id' => $t->id, 'name' => $t->displayName()])->values(),
            'drivers' => Driver::query()->where('is_active', true)->orderBy('name')
                ->with('vehicle:id,driver_id,plate_number,plate_letters')
                ->get(['id', 'name'])
                ->map(fn (Driver $d) => [
                    'id'   => $d->id,
                    'name' => $d->name,
                    'has_vehicle' => $d->vehicle && $d->vehicle->id !== $current?->id ? $d->vehicle->plateText() : null,
                ])->values(),
        ];
    }
}
