<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\CompanySetting;
use App\Models\CargoType;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\ExpenseCategory;
use App\Models\RateCard;
use App\Models\Trip;
use App\Models\TripCharge;
use App\Models\TripCollection;
use App\Models\TripEvent;
use App\Models\TripExpense;
use App\Models\TripRoute;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WalletEntry;
use App\Models\WalletTransfer;
use App\Services\CompanyDefaults;
use App\Services\Trips\SettlementService;
use App\Services\Trips\TransferService;
use App\Services\Trips\TripFigures;
use App\Services\Trips\TripService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\TripController ( /office/trips )
//  Location: app/Http/Controllers/Office/TripController.php
//
//  Scope §6.3 — the trips list and the trip detail screen:
//    index   → list: search (trip no., driver, customer, plate),
//              status filters with counts, dates, totals of what is
//              filtered (revenue, cost, profit), Excel export button
//    store   → new trip (App\Services\Trips\TripService::create):
//              price from the rate card, custody suggested from the
//              route budget, the company's transfer policy
//    show    → the trip file: header + step tracker, wallets,
//              transfers, cash log, revenue & expenses with budget
//              flags, profitability, timeline, photos, settlement
//    update  → edit what may still change (price needs Edit price)
//    export  → the filtered list as an Excel file
//    print   → the printable trip order (no prices — it goes to the driver)
//    pod / receipt / collectionReceipt → the photos, only through
//              this permission-checked door (they are never public)
//
//  The actions that move a trip or its money are in
//  TripActionController and TripMoneyController.
//  Permissions: trips.view / create / edit (+ edit_price).
// ══════════════════════════════════════════════════════════════════

class TripController extends Controller
{
    use RunsTripRules;

    public const FILTERS = ['planned', 'running', 'delivered', 'settled', 'cancelled'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);

        $trips = TripFigures::withMoney((clone $query)->select('trips.*'))
            ->with(['customer:id,name_ar,name_en', 'route:id,origin_ar,origin_en,destination_ar,destination_en', 'vehicle:id,plate_number,plate_letters,ownership', 'driver:id,name', 'invoice:id,number'])
            ->orderByRaw("CASE WHEN trips.status IN ('accepted','loading','on_road') THEN 0 WHEN trips.status = 'delivered' THEN 1 WHEN trips.status = 'planned' THEN 2 ELSE 3 END")
            ->orderByDesc('trips.loading_at')
            ->paginate(25)
            ->withQueryString();

        $cash = $this->cashHeld($trips->getCollection()->pluck('id')->all());
        $seeProfit = $request->user('web')->can('trips.see_profit');

        return Inertia::render('Office/Trips/Index', [
            'trips'   => [
                'data'  => $trips->getCollection()->map(fn (Trip $t) => $this->row($t, $cash[$t->id] ?? null, $seeProfit))->values(),
                'links' => $trips->linkCollection(),
                'total' => $trips->total(),
            ],
            'totals'  => $this->totals($query, $seeProfit),
            'counts'  => $this->counts($filters),
            'filters' => $filters,
            'options' => fn () => $request->user('web')->can('trips.create') ? $this->formOptions() : null,
        ]);
    }

    public function store(Request $request, TripService $service): RedirectResponse
    {
        $data = $this->validated($request);

        // Transfer rules are set by whoever holds "Edit transfer rules" — others get the company default.
        if (! $request->user('web')->can('trips.edit_policy')) {
            unset($data['transfer_policy'], $data['auto_transfer_limit']);
        }

        return $this->attempt(function () use ($service, $data, $request) {
            $trip = $service->create($data, $request->user('web'));

            return redirect()->route('office.trips.show', $trip)->with('success', __('trips.ok.created', ['number' => $trip->number]));
        });
    }

    public function show(Request $request, Trip $trip, TripFigures $figures, SettlementService $settlement, TransferService $transfers): Response
    {
        $trip->load([
            'customer:id,name_ar,name_en,may_pay_driver_cash', 'route', 'vehicle.vehicleType', 'cargoType', 'driver:id,name,mobile',
            'charges', 'expenses.category', 'expenses.transfer:id,status', 'collections', 'settlement.settledBy:id,name', 'rating.author:id,name', 'clientRequest:id,number', 'invoice:id,number,issued_on',
            'transfers' => fn ($q) => $q->with('decider:id,name', 'reviewer:id,name')->latest('requested_at'),
            'events' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id'),
        ]);

        $user = $request->user('web');
        $budget = collect($trip->standard_budget ?? []);
        $overIds = collect($figures->budget($trip)['rows'])->where('over', true)->pluck('category_id')->all();

        return Inertia::render('Office/Trips/Show', [
            'trip'        => $this->detail($trip),
            'figures'     => $this->withoutProfit($figures->forTrip($trip), $request->user('web')->can('trips.see_profit')),
            // Step 5: where the trip came from and what the client said about it
            'feedback'    => [
                'request'    => $trip->clientRequest ? ['id' => $trip->clientRequest->id, 'number' => $trip->clientRequest->number] : null,
                'rating'     => $trip->rating ? ['stars' => $trip->rating->stars, 'comment' => $trip->rating->comment, 'by' => $trip->rating->author?->name, 'at' => $trip->rating->created_at?->toIso8601String()] : null,
                'complaints' => \App\Models\ClientComplaint::query()->where('trip_id', $trip->id)->latest('id')->get()
                    ->map(fn ($c) => ['id' => $c->id, 'subject' => $c->subject, 'status' => $c->status])->values(),
            ],
            'wallets'     => $figures->wallets($trip),
            'available'   => $transfers->available($trip),
            'settlement'  => $trip->status === 'delivered' ? $settlement->preview($trip) : null,
            'nextStep'    => $this->nextStep($trip),
            'charges'     => $trip->charges->map(fn (TripCharge $c) => $c->only(['id', 'kind', 'label', 'amount']))->values(),
            'expenses'    => $trip->expenses->sortByDesc('spent_at')->map(fn (TripExpense $e) => [
                'id'          => $e->id,
                'category_id' => $e->expense_category_id,
                'category'    => $e->is_personal ? null : $e->category?->displayName(),
                'icon'        => $e->category?->icon,
                'is_personal' => $e->is_personal,
                'paid_from'   => $e->paid_from,
                'amount'      => $e->amount,
                'standard'    => $e->expense_category_id ? (float) ($budget[$e->expense_category_id] ?? 0) : null,
                'over'        => in_array($e->expense_category_id, $overIds),
                'note'        => $e->note,
                'spent_at'    => $e->spent_at?->toIso8601String(),
                'source'      => $e->source,
                'has_receipt' => (bool) $e->receipt_path,
                'located'     => $e->lat !== null,
                'transfer'    => $e->transfer?->status,
            ])->values(),
            'collections' => $trip->collections->sortByDesc('received_at')->map(fn (TripCollection $c) => [
                'id'          => $c->id,
                'amount'      => $c->amount,
                'received_at' => $c->received_at?->toIso8601String(),
                'recorded_by' => $c->recorded_by,
                'state'       => $c->state(),
                'counts'      => $c->counts(),
                'note'        => $c->note,
                'dispute_note'=> $c->dispute_note,
                'has_receipt' => (bool) $c->receipt_path,
            ])->values(),
            'transfers'   => $trip->transfers->map(fn (WalletTransfer $t) => $this->transferRow($t, $user, $transfers))->values(),
            'events'      => $trip->events->map(fn (TripEvent $e) => [
                'id'      => $e->id,
                'type'    => $e->type,
                'at'      => $e->occurred_at?->toIso8601String(),
                'actor'   => $e->actor_name,
                'by'      => $e->actor_type,
                'note'    => $e->note,
                'meta'    => $e->meta,
                'located' => $e->lat !== null,
            ])->values(),
            'options'     => [
                'categories' => ExpenseCategory::query()->where('is_active', true)->ordered()->get()
                    ->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'name' => $c->displayName(), 'icon' => $c->icon, 'code' => $c->code,
                        'standard' => (float) ($budget[$c->id] ?? 0)])->values(),
                'form'       => $user->can('trips.edit') ? $this->formOptions($trip) : null,
            ],
        ]);
    }

    public function update(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate([
            'vehicle_id'       => ['sometimes', 'integer', Rule::exists('vehicles', 'id')->where('company_id', $trip->company_id)],
            'driver_id'        => ['nullable', 'integer', Rule::exists('drivers', 'id')->where('company_id', $trip->company_id)],
            'loading_at'       => ['sometimes', 'date'],
            'cargo_type_id'    => ['nullable', 'integer', Rule::exists('cargo_types', 'id')->where('company_id', $trip->company_id)],
            'weight_tons'      => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'notes'            => ['nullable', 'string', 'max:2000'],
            'client_pays_cash' => ['sometimes', 'boolean'],
            'custody_planned'  => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'freight_price'    => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);

        return $this->attempt(fn () => $service->update($trip, $data, $request->user('web')), __('trips.ok.saved'));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $trips = TripFigures::withMoney($this->filtered($filters)->select('trips.*'))
            ->with(['customer:id,name_ar,name_en', 'route', 'cargoType', 'vehicle:id,plate_number,plate_letters,ownership', 'driver:id,name'])
            ->orderByDesc('trips.loading_at')
            ->limit(10000)
            ->get();

        $seeProfit = $request->user('web')->can('trips.see_profit');
        $lastCol = $seeProfit ? 'O' : 'M';
        $ar = app()->getLocale() === 'ar';
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setRightToLeft($ar);
        $sheet->setTitle($ar ? 'الرحلات' : 'Trips');

        $head = $ar
            ? ['رقم الرحلة', 'الحالة', 'تاريخ التحميل', 'العميل', 'المسار', 'كم', 'الشاحنة', 'مؤجرة', 'السائق', 'البضاعة', 'الحمولة (طن)', 'الإيراد', 'التكلفة المباشرة', 'الربح المباشر', 'الهامش %']
            : ['Trip', 'Status', 'Loading date', 'Customer', 'Route', 'km', 'Truck', 'Hired', 'Driver', 'Cargo', 'Weight (tons)', 'Revenue', 'Direct cost', 'Direct profit', 'Margin %'];
        // The last two columns (direct profit, margin) are for people who may see profit.
        $sheet->fromArray($seeProfit ? $head : array_slice($head, 0, 13), null, 'A1');
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

        $r = 2;
        foreach ($trips as $t) {
            $revenue = TripFigures::rowRevenue($t);
            $cost = round((float) $t->cost_total, 2);
            $line = [
                $t->number, __('trips.status.'.$t->status), $t->loading_at?->format('Y-m-d H:i'), $t->customer?->displayName(),
                $t->route?->displayName(), $t->km, $t->vehicle?->plateText(), $t->is_hired ? ($ar ? 'نعم' : 'Yes') : '',
                $t->driver?->name, $t->cargoType?->displayName(), $t->weight_tons, $revenue, $cost, round($revenue - $cost, 2), $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null,
            ];
            $sheet->fromArray($seeProfit ? $line : array_slice($line, 0, 13), null, "A{$r}");
            $r++;
        }

        $sheet->getStyle($seeProfit ? "L2:N{$r}" : "L2:M{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $name = 'el-tara-trips-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($sheet) {
            (new Xlsx($sheet->getParent()))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function print(Trip $trip): \Illuminate\Contracts\View\View
    {
        $trip->load(['customer', 'route', 'vehicle', 'cargoType', 'driver', 'company', 'events' => fn ($q) => $q->where('type', 'custody_issued')]);
        $categories = ExpenseCategory::query()->whereIn('id', array_keys($trip->standard_budget ?? []))->ordered()->get();

        return view('trips.print', [
            'trip'       => $trip,
            'budget'     => $categories->map(fn ($c) => ['name' => $c->displayName(), 'amount' => (float) ($trip->standard_budget[$c->id] ?? 0)]),
            'custody'    => round((float) $trip->events->sum(fn ($e) => $e->meta['amount'] ?? 0), 2),
            'locale'     => app()->getLocale(),
        ]);
    }

    public function pod(Trip $trip): \Symfony\Component\HttpFoundation\Response
    {
        return $this->file($trip->pod_path);
    }

    public function receipt(Trip $trip, TripExpense $expense): \Symfony\Component\HttpFoundation\Response
    {
        return $this->file($expense->receipt_path);
    }

    public function collectionReceipt(Trip $trip, TripCollection $collection): \Symfony\Component\HttpFoundation\Response
    {
        return $this->file($collection->receipt_path);
    }

    // ── List helpers ───────────────────────────────────────────────

    private function filters(Request $request): array
    {
        $status = $request->query('status');

        return [
            'search' => trim((string) $request->query('search')),
            'status' => in_array($status, self::FILTERS, true) ? $status : null,
            'from'   => $request->date('from')?->toDateString(),
            'to'     => $request->date('to')?->toDateString(),
        ];
    }

    private function filtered(array $f, bool $withStatus = true): Builder
    {
        $s = $f['search'];

        return Trip::query()
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('trips.number', 'like', "%{$s}%")
                ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', "%{$s}%"))
                ->orWhereHas('driver', fn ($d) => $d->where('name', 'like', "%{$s}%"))
                ->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', "%{$s}%")->orWhere('name_en', 'like', "%{$s}%"))
                ->orWhereHas('vehicle', fn ($v) => $v->where('plate_number', 'like', "%{$s}%")->orWhere('plate_letters', 'like', "%{$s}%"))))
            ->when($f['from'], fn ($q) => $q->whereDate('trips.loading_at', '>=', $f['from']))
            ->when($f['to'], fn ($q) => $q->whereDate('trips.loading_at', '<=', $f['to']))
            ->when($withStatus && $f['status'], fn ($q) => match ($f['status']) {
                'running' => $q->whereIn('trips.status', Trip::IN_PROGRESS),
                default   => $q->where('trips.status', $f['status']),
            });
    }

    /** Takes every profit figure out of a trip's figures (profit, margin, per km, G&A share, true profit). */
    private function withoutProfit(array $f, bool $seeProfit): array
    {
        if ($seeProfit) {
            return $f;
        }

        foreach (['profit', 'margin', 'profit_per_km', 'ga_rate', 'true_profit'] as $key) {
            $f[$key] = null;
        }
        $f['ga_share'] = 0.0;
        $f['ga_known'] = false;
        $f['ga_parts'] = [];
        $f['true_estimate'] = false;

        return $f;
    }

    /** Totals of everything filtered (not just this page) — 3 small queries. */
    private function totals(Builder $query, bool $seeProfit = true): array
    {
        $base = (clone $query)->where('trips.status', '!=', 'cancelled');
        $ids = (clone $base)->select('trips.id');

        $freight = (float) (clone $base)->sum('trips.freight_price');
        $charges = (float) TripCharge::query()->whereIn('trip_id', $ids)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'deduction' THEN -amount ELSE amount END), 0) as t")->value('t');
        $cost = (float) TripExpense::query()->whereIn('trip_id', $ids)->where('is_personal', false)->sum('amount');
        $revenue = round($freight + $charges, 2);

        return [
            'count'   => (clone $base)->count(),
            'revenue' => $revenue,
            'cost'    => round($cost, 2),
            // Without the "see profit" permission the figure is not even sent to the browser.
            'profit'  => $seeProfit ? round($revenue - $cost, 2) : null,
            'km'      => (int) (clone $base)->sum('trips.km'),
        ];
    }

    private function counts(array $filters): array
    {
        $rows = $this->filtered($filters, false)->selectRaw('trips.status, count(*) as n')->groupBy('trips.status')->pluck('n', 'status');

        return [
            'all'       => (int) $rows->sum(),
            'planned'   => (int) ($rows['planned'] ?? 0),
            'running'   => (int) collect(Trip::IN_PROGRESS)->sum(fn ($s) => $rows[$s] ?? 0),
            'delivered' => (int) ($rows['delivered'] ?? 0),
            'settled'   => (int) ($rows['settled'] ?? 0),
            'cancelled' => (int) ($rows['cancelled'] ?? 0),
        ];
    }

    /** Custody + collections the driver holds, per trip of this page — one query. */
    private function cashHeld(array $tripIds): array
    {
        return WalletEntry::query()->whereIn('trip_id', $tripIds ?: [0])->whereIn('wallet', ['custody', 'collections'])
            ->selectRaw('trip_id, wallet, SUM(amount) as total')->groupBy('trip_id', 'wallet')->get()
            ->groupBy('trip_id')
            ->map(fn ($rows) => [
                'custody'     => round((float) $rows->firstWhere('wallet', 'custody')?->total, 2),
                'collections' => round((float) $rows->firstWhere('wallet', 'collections')?->total, 2),
            ])->all();
    }

    private function row(Trip $t, ?array $cash, bool $seeProfit = true): array
    {
        $revenue = TripFigures::rowRevenue($t);
        $cost = round((float) $t->cost_total, 2);

        return [
            'id'         => $t->id,
            'number'     => $t->number,
            'status'     => $t->status,
            'customer'   => $t->customer?->displayName(),
            'route'      => $t->route?->displayName(),
            'vehicle'    => $t->vehicle ? ['number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters] : null,
            'is_hired'   => $t->is_hired,
            'driver'     => $t->driver?->name,
            'invoice'    => $t->invoice?->number,
            'loading_at' => $t->loading_at?->toIso8601String(),
            'km'         => $t->km,
            'revenue'    => $revenue,
            'cost'       => $cost,
            'profit'     => $seeProfit ? round($revenue - $cost, 2) : null,
            'margin'     => $seeProfit && $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null,
            'cash'       => $cash,
        ];
    }

    // ── Detail helpers ─────────────────────────────────────────────

    private function detail(Trip $t): array
    {
        return [
            'id'                  => $t->id,
            'number'              => $t->number,
            'status'              => $t->status,
            'is_hired'            => $t->is_hired,
            'invoice'             => $t->invoice ? ['id' => $t->invoice->id, 'number' => $t->invoice->number, 'issued_on' => $t->invoice->issued_on ? substr((string) $t->invoice->issued_on, 0, 10) : null] : null,
            'customer'            => ['id' => $t->customer_id, 'name' => $t->customer?->displayName()],
            'route'               => [
                'id'   => $t->trip_route_id,
                'name' => $t->route?->displayName(),
                'from' => app()->getLocale() === 'en' && $t->route?->origin_en ? $t->route->origin_en : $t->route?->origin_ar,
                'to'   => app()->getLocale() === 'en' && $t->route?->destination_en ? $t->route->destination_en : $t->route?->destination_ar,
            ],
            'vehicle'             => $t->vehicle ? [
                'id' => $t->vehicle->id, 'number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters,
                'type' => $t->vehicle->vehicleType?->displayName(), 'owner' => $t->vehicle->owner_name,
            ] : null,
            'driver'              => $t->driver ? ['id' => $t->driver->id, 'name' => $t->driver->name, 'mobile' => $t->driver->mobile] : null,
            'loading_at'          => $t->loading_at?->toIso8601String(),
            'accepted_at'         => $t->accepted_at?->toIso8601String(),
            'custody_issued_at'   => $t->custody_issued_at?->toIso8601String(),
            'loading_started_at'  => $t->loading_started_at?->toIso8601String(),
            'departed_at'         => $t->departed_at?->toIso8601String(),
            'delivered_at'        => $t->delivered_at?->toIso8601String(),
            'settled_at'          => $t->settled_at?->toIso8601String(),
            'cancelled_at'        => $t->cancelled_at?->toIso8601String(),
            'cancel_reason'       => $t->cancel_reason,
            'km'                  => $t->km,
            'planned_hours'       => $t->planned_hours,
            'cargo_type_id'       => $t->cargo_type_id,
            'cargo'               => $t->cargoType?->displayName(),
            'weight_tons'         => $t->weight_tons,
            'notes'               => $t->notes,
            'freight_price'       => $t->freight_price,
            'price_source'        => $t->price_source,
            'client_pays_cash'    => $t->client_pays_cash,
            'custody_planned'     => $t->custody_planned,
            'transfer_policy'     => $t->transfer_policy,
            'auto_transfer_limit' => $t->auto_transfer_limit,
            'has_pod'             => (bool) $t->pod_path,
            'pod_receiver'        => $t->pod_receiver,
            'settlement'          => $t->settlement ? [
                'custody'     => $t->settlement->custody_balance,
                'collections' => $t->settlement->collections_balance,
                'pocket'      => $t->settlement->pocket_balance,
                'net'         => $t->settlement->net_amount,
                'unconfirmed' => $t->settlement->unconfirmed_amount,
                'note'        => $t->settlement->note,
                'received_by' => $t->settlement->cash_received_by,
                'by'          => $t->settlement->settledBy?->name,
                'at'          => $t->settlement->settled_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** The one "next step" button of the trip, or null when there is none. */
    private function nextStep(Trip $t): ?string
    {
        return match ($t->status) {
            'planned'   => 'accept',
            'accepted'  => ! $t->is_hired && $t->custody_planned > 0 && ! $t->custody_issued_at ? 'custody' : 'loading',
            'loading'   => 'depart',
            'on_road'   => 'deliver',
            'delivered' => 'settle',
            default     => null,
        };
    }

    public static function transferRow(WalletTransfer $t, User $user, TransferService $service): array
    {
        return [
            'id'           => $t->id,
            'amount'       => $t->amount,
            'reason'       => $t->reason,
            'status'       => $t->status,
            'policy'       => $t->policy,
            'policy_limit' => $t->policy_limit,
            'requested_by' => $t->requested_by_name,
            'by_driver'    => $t->requested_by_type === 'driver',
            'requested_at' => $t->requested_at?->toIso8601String(),
            'decided_by'   => $t->decider?->name,
            'decided_at'   => $t->decided_at?->toIso8601String(),
            'note'         => $t->decision_note,
            'reviewed_by'  => $t->reviewer?->name,
            'reviewed_at'  => $t->reviewed_at?->toIso8601String(),
            'is_expense'   => $t->trip_expense_id !== null,
            'can_approve'  => $t->status === 'pending' && $service->canApprove($user, $t),
        ];
    }

    // ── Form ───────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        $companyId = $request->user('web')->company_id;
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        return $request->validate([
            'customer_id'         => ['required', 'integer', $exists('customers')],
            'trip_route_id'       => ['required', 'integer', $exists('trip_routes')],
            'vehicle_id'          => ['required', 'integer', $exists('vehicles')],
            'driver_id'           => ['nullable', 'integer', $exists('drivers')],
            'loading_at'          => ['required', 'date'],
            'cargo_type_id'       => ['nullable', 'integer', $exists('cargo_types')],
            'weight_tons'         => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            'freight_price'       => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'client_pays_cash'    => ['boolean'],
            'custody_planned'     => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'transfer_policy'     => ['required', Rule::in(CompanySetting::POLICIES)],
            'auto_transfer_limit' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'hire_fee'            => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
    }

    /** Everything the new-trip / edit form needs, in one go. */
    private function formOptions(?Trip $current = null): array
    {
        $settings = CompanyDefaults::ensure(auth('web')->user()->company_id);

        $planned = Trip::query()->where('status', 'planned')->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->get(['id', 'number', 'vehicle_id', 'driver_id']);
        $running = Trip::query()->inProgress()->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->get(['id', 'number', 'vehicle_id', 'driver_id', 'status']);

        $state = function (string $key, int $id) use ($planned, $running) {
            $booked = $planned->firstWhere($key, $id);
            $on = $running->firstWhere($key, $id);

            return ['booked' => $booked?->number, 'on_trip' => $on?->number];
        };

        return [
            'customers' => Customer::query()->where('is_active', true)->orderBy('name_ar')->get()
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->displayName(), 'pays_cash' => $c->may_pay_driver_cash])->values(),
            'routes'    => TripRoute::query()->where('is_active', true)->with('budgets.category')->orderBy('origin_ar')->get()
                ->map(fn (TripRoute $r) => [
                    'id' => $r->id, 'name' => $r->displayName(), 'weight' => $r->weight_tons, 'km' => $r->km_round_trip, 'hours' => $r->usual_hours,
                    'standard' => $r->standardTotal(), 'custody' => $r->suggestedCustody($settings->custody_buffer_percent),
                ])->values(),
            'rates'     => RateCard::query()->get(['customer_id', 'trip_route_id', 'price'])
                ->groupBy('customer_id')->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->trip_route_id => $r->price])),
            'vehicles'  => Vehicle::query()->with(['driver:id,name', 'vehicleType'])->orderBy('ownership')->orderBy('plate_number')->get()
                ->map(fn (Vehicle $v) => [
                    'id' => $v->id, 'number' => $v->plate_number, 'letters' => $v->plate_letters, 'type' => $v->vehicleType?->displayName(),
                    'hired' => $v->isHired(), 'owner' => $v->owner_name, 'driver_id' => $v->driver_id, 'driver' => $v->driver?->name,
                    'maintenance' => $v->status === 'maintenance', ...$state('vehicle_id', $v->id),
                ])->values(),
            'drivers'   => Driver::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Driver $d) => ['id' => $d->id, 'name' => $d->name, ...$state('driver_id', $d->id)])->values(),
            'cargo_types' => CargoType::query()->ordered()->where(fn ($q) => $q->where('is_active', true)->when($current?->cargo_type_id, fn ($w) => $w->orWhere('id', $current->cargo_type_id)))->get()
                ->map(fn (CargoType $c) => ['id' => $c->id, 'name' => $c->displayName()])->values(),
            'defaults'  => [
                'policy' => $settings->default_transfer_policy,
                'limit'  => $settings->auto_transfer_limit,
                'buffer' => $settings->custody_buffer_percent,
            ],
        ];
    }

    private function file(?string $path): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($path && Storage::disk('trip_files')->exists($path), 404);

        return Storage::disk('trip_files')->response($path, null, ['Cache-Control' => 'private, max-age=600']);
    }
}
