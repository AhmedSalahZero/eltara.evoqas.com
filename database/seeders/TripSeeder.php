<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Driver;
use App\Models\ExpenseCategory;
use App\Models\RateCard;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trips\Actor;
use App\Services\Trips\CollectionService;
use App\Services\Trips\ExpenseService;
use App\Services\Trips\SettlementService;
use App\Services\Trips\TransferService;
use App\Services\Trips\TripService;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripSeeder (Step 3 demo trips)
//  Location: database/seeders/TripSeeder.php
//
//  Gives "Nile Heavy Transport" trips in every state, so each Step 3
//  screen has something real to show:
//    · 7 settled trips over the last weeks (custody, expenses, cash
//      from clients, transfers — all closed);
//    · trips on the road / loading / accepted, with custody, expenses,
//      cash from clients, one transfer WAITING for approval (above the
//      fleet manager's 1,000 limit) and automatic ones TO REVIEW;
//    · 2 delivered trips waiting for settlement (one blocked by a
//      disputed cash amount);
//    · a personal expense (→ advance) and an own-pocket expense;
//    · 2 planned trips (one on a hired truck) and 1 cancelled.
//  Everything goes through the real services (App\Services\Trips), at
//  the right moments in the past (the clock is moved while seeding),
//  so every wallet balance adds up exactly as it would in real use.
//  Delivery-note and receipt "photos" are small drawn placeholders.
//  Runs once: if the company already has trips, it does nothing.
// ══════════════════════════════════════════════════════════════════

class TripSeeder extends Seeder
{
    private TripService $trips;

    private ExpenseService $expenses;

    private CollectionService $collections;

    private TransferService $transfers;

    private SettlementService $settlement;

    private User $admin;

    /** @var array<string,int> category code => id */
    private array $cat = [];

    public function run(): void
    {
        $company = Company::query()->where('name_en', 'Nile Heavy Transport')->first();
        if (! $company) {
            return;
        }
        if (Trip::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            $this->command?->info('Step 3 demo trips: already there — nothing added.');

            return;
        }

        $this->admin = User::query()->where('company_id', $company->id)->where('role', UserRole::CompanyAdmin->value)->firstOrFail();
        $this->trips = app(TripService::class);
        $this->expenses = app(ExpenseService::class);
        $this->collections = app(CollectionService::class);
        $this->transfers = app(TransferService::class);
        $this->settlement = app(SettlementService::class);

        try {
            Tenant::forCompany($company->id, fn () => $this->seed());
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info('Step 3 demo trips: settled, on the road, waiting for approval and settlement, planned.');
    }

    private function seed(): void
    {
        $this->cat = ExpenseCategory::query()->pluck('id', 'code')->all();

        $own = Vehicle::query()->where('ownership', 'own')->where('status', 'available')->whereNotNull('driver_id')->orderBy('id')->get()->values();
        $hired = Vehicle::query()->where('ownership', 'hired')->orderBy('id')->get()->values();
        $rates = RateCard::query()->with('route.budgets.category')->orderBy('id')->get()->values();

        if ($own->count() < 7 || $rates->count() < 10) {
            $this->command?->warn('Step 3 demo trips need the Step 2 demo data first (MasterDataSeeder).');

            return;
        }

        $rate = fn (int $i) => $rates[$i % $rates->count()];
        $base = now()->copy()->startOfHour();

        // ── Settled over the last weeks ───────────────────────────
        foreach ([[26, 0, 0], [23, 1, 3], [20, 2, 6], [16, 3, 9], [12, 4, 12], [9, 5, 1], [6, 0, 4]] as $n => [$daysAgo, $v, $r]) {
            $this->trip($own[$v], $rate($r), $base->copy()->subDays($daysAgo)->setTime(6, 0), 'settled', [
                'cash'  => $n % 3 === 0 ? 3000 : 0,
                'extra' => $n === 2 ? ['extra', 'انتظار يوم إضافي في التفريغ', 800] : null,
                'over'  => $n === 4 ? 1.35 : 1.0,
            ]);
        }

        // ── On the way ────────────────────────────────────────────
        // On the road, client paid cash; a transfer above 1,000 waits for approval.
        $this->trip($own[0], $rate(1), $base->copy()->subHours(9), 'on_road', [
            'cash' => 5000, 'pending_transfer' => 1800, 'policy' => 'limit',
        ]);
        // On the road, night trip: automatic transfers, one to review; a personal expense.
        $this->trip($own[1], $rate(5), $base->copy()->subHours(14), 'on_road', [
            'cash' => 4000, 'auto_transfer' => 1500, 'policy' => 'auto', 'personal' => 300, 'expense_from_collections' => 450,
        ]);
        // On the road, overspending on fuel; the driver paid tolls from his pocket.
        $this->trip($own[2], $rate(3), $base->copy()->subHours(7), 'on_road', ['over' => 1.3, 'pocket' => 260]);
        // Loading now.
        $this->trip($own[3], $rate(7), $base->copy()->subHours(2), 'loading');
        // Accepted, custody handed over.
        $this->trip($own[4], $rate(2), $base->copy()->subHour(), 'accepted');

        // ── Delivered, waiting for settlement ─────────────────────
        $this->trip($own[5], $rate(4), $base->copy()->subDays(2)->setTime(5, 0), 'delivered', ['cash' => 2500]);
        $this->trip($own[6], $rate(6), $base->copy()->subDays(3)->setTime(7, 0), 'delivered', ['cash' => 3500, 'dispute' => 500]);

        // ── Planned ───────────────────────────────────────────────
        $this->trip($own[3], $rate(8), $base->copy()->addDay()->setTime(6, 0), 'planned');
        if ($hired->isNotEmpty()) {
            $this->trip($hired[0], $rate(0), $base->copy()->addDays(2)->setTime(7, 0), 'planned', ['hire_fee' => 7800]);
        }

        // ── Cancelled ─────────────────────────────────────────────
        $cancelled = $this->trip($own[6], $rate(9), $base->copy()->subDays(4)->setTime(8, 0), 'planned');
        Carbon::setTestNow($base->copy()->subDays(4)->setTime(6, 30));
        $this->trips->cancel($cancelled, 'العميل أجّل الشحنة', $this->admin);
        Carbon::setTestNow();
    }

    /**
     * Create one trip and move it to $until, at realistic times from $start.
     *
     * @param  array<string,mixed>  $x  extras: cash, pending_transfer, auto_transfer, policy, personal,
     *                                  expense_from_collections, pocket, over, extra, dispute, hire_fee
     */
    /** The goods type with this name for the demo company (created once). */
    private function cargoId(string $name): int
    {
        return \App\Models\CargoType::query()->withoutGlobalScopes()
            ->firstOrCreate(['company_id' => $this->admin->company_id, 'name_ar' => $name], ['is_active' => true, 'sort' => 500])->id;
    }

    private function trip(Vehicle $vehicle, RateCard $rate, Carbon $start, string $until, array $x = []): Trip
    {
        $route = $rate->route;
        $user = Actor::user($this->admin);
        $at = fn (float $hours) => Carbon::setTestNow($start->copy()->addMinutes((int) round($hours * 60)));

        // Booked the day before — but never "in the future" for trips planned ahead.
        Carbon::setTestNow(min(Carbon::now(), $start->copy()->subHours(20)));
        $trip = $this->trips->create([
            'customer_id'     => $rate->customer_id,
            'trip_route_id'   => $route->id,
            'vehicle_id'      => $vehicle->id,
            'loading_at'      => $start->copy(),
            'cargo_type_id'   => $this->cargoId(['حديد تسليح', 'أسمنت معبأ', 'رخام خام', 'حاويات 40 قدم', 'دقيق', 'أخشاب'][$route->id % 6]),
            'weight_tons'     => [28, 32.5, 24, 20, 30, 18][$route->id % 6],
            'client_pays_cash'=> ($x['cash'] ?? 0) > 0,
            'transfer_policy' => $x['policy'] ?? 'limit',
            'hire_fee'        => $x['hire_fee'] ?? null,
        ], $this->admin);

        if ($until === 'planned') {
            Carbon::setTestNow();

            return $trip;
        }

        $driver = $trip->driver_id ? Driver::query()->find($trip->driver_id) : null;
        $asDriver = $driver ? Actor::driver($driver) : $user;
        $hours = max(6.0, (float) ($route->usual_hours ?? 12));
        $budget = $route->budgets->mapWithKeys(fn ($b) => [$b->category->code => (float) $b->amount]);
        $over = (float) ($x['over'] ?? 1.0);

        $at(0);
        $this->trips->accept($trip, $asDriver);
        if ($trip->custody_planned > 0) {
            $at(0.4);
            $this->trips->issueCustody($trip, $trip->custody_planned, $user);
        }
        if ($until === 'accepted') {
            return $this->done($trip);
        }

        $at(1);
        $this->trips->startLoading($trip, $asDriver);
        if (isset($budget['labor'])) {
            $at(2);
            $this->expense($trip, 'labor', $budget['labor'], 'custody', $asDriver);
        }
        if ($until === 'loading') {
            return $this->done($trip);
        }

        $at(3);
        $this->trips->depart($trip, $asDriver);

        // Fuel: 40% cash from custody, the rest on the company fuel card.
        if (isset($budget['fuel'])) {
            $at(4);
            $this->expense($trip, 'fuel', round($budget['fuel'] * 0.6 * $over, -1), 'company', $user, 'كارت الوقود');
            $at(4.2);
            $this->expense($trip, 'fuel', round($budget['fuel'] * 0.4 * $over, -1), 'custody', $asDriver);
        }
        foreach (['toll' => 4.5, 'weigh' => 5, 'allow' => 5.5, 'night' => 8] as $code => $h) {
            if (isset($budget[$code]) && $h < $hours) {
                $at($h);
                $pocket = $code === 'toll' && isset($x['pocket']);
                $this->expense($trip, $code, $pocket ? (float) $x['pocket'] : $budget[$code], $pocket ? 'own_pocket' : 'custody', $asDriver);
            }
        }

        if (($x['cash'] ?? 0) > 0) {
            $at(min(6, $hours - 1));
            $cash = $this->collections->record($trip->fresh(), (float) $x['cash'], 'driver', $asDriver, 'دفعة من العميل عند التحميل');
            // On older trips the client has already confirmed it (Step 5 portal).
            if (in_array($until, ['settled', 'delivered'], true)) {
                $at(min(6, $hours - 1) + 2);
                $this->collections->confirmByClient($cash, Actor::system());
            }
        }
        if (isset($x['personal'])) {
            $at(6.5);
            $this->expenses->record($trip->fresh(), ['is_personal' => true, 'paid_from' => 'custody', 'amount' => $x['personal'], 'note' => 'سلفة شخصية على الطريق'], $asDriver);
        }
        if (isset($x['expense_from_collections'])) {
            $at(7);
            $this->expense($trip, 'repair', (float) $x['expense_from_collections'], 'collections', $asDriver, 'تغيير خرطوم هواء');
        }
        if (isset($x['pending_transfer'])) {
            $at(7.5);
            $this->transfers->request($trip->fresh(), (float) $x['pending_transfer'], 'العهدة خلصت — سولار إضافي', $asDriver);
        }
        if (isset($x['auto_transfer'])) {
            $at(8);
            $this->transfers->request($trip->fresh(), (float) $x['auto_transfer'], 'مبيت وكارتة الرجوع', $asDriver);
        }
        if ($x['extra'] ?? null) {
            [$kind, $label, $amount] = $x['extra'];
            $this->trips->addCharge($trip->fresh(), $kind, $label, $amount, $this->admin);
        }
        if ($until === 'on_road') {
            return $this->done($trip);
        }

        $at($hours);
        $this->trips->deliver($trip->fresh(), $this->photo($trip, 'delivery', 'إذن تسليم مختوم'), 'أمين المخزن', $asDriver);

        if (isset($x['dispute'])) {
            $at($hours + 1);
            $c = $this->collections->record($trip->fresh(), (float) $x['dispute'], 'driver', $asDriver, 'دفعة إضافية عند التفريغ');
            $this->collections->dispute($c, 'client', Actor::system(), 'العميل: لم ندفع هذا المبلغ');
        }
        if ($until === 'delivered') {
            return $this->done($trip);
        }

        // Settle: the auto transfers are reviewed, pending ones decided first.
        $at($hours + 18);
        $this->settlement->settle($trip->fresh(), $this->admin, 'تمت التسوية بالخزينة');

        return $this->done($trip);
    }

    private function expense(Trip $trip, string $code, float $amount, string $from, Actor $actor, ?string $note = null): void
    {
        if (! isset($this->cat[$code]) || $amount <= 0) {
            return;
        }

        $expense = $this->expenses->record($trip->fresh(), [
            'expense_category_id' => $this->cat[$code], 'paid_from' => $from, 'amount' => $amount, 'note' => $note,
        ], $actor);

        if ($from !== 'company') {
            $expense->forceFill(['receipt_path' => $this->photo($trip, 'receipts', 'إيصال '.number_format($amount))])->save();
        }
    }

    private function done(Trip $trip): Trip
    {
        Carbon::setTestNow();

        return $trip->fresh();
    }

    /** A small drawn placeholder standing in for a phone photo. */
    private function photo(Trip $trip, string $folder, string $label): string
    {
        $path = "trips/{$trip->id}/{$folder}/demo-".uniqid().'.svg';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="640" viewBox="0 0 480 640">'
            .'<rect width="480" height="640" fill="#F7FBFE"/>'
            .'<g stroke="#D7E6F2">'.implode('', array_map(fn ($y) => "<line x1=\"30\" y1=\"{$y}\" x2=\"450\" y2=\"{$y}\"/>", range(120, 560, 40))).'</g>'
            .'<text x="240" y="80" font-family="Tahoma, Arial" font-size="30" text-anchor="middle" fill="#123055">'.htmlspecialchars($label).'</text>'
            .'<text x="240" y="600" font-family="Arial" font-size="22" text-anchor="middle" fill="#5C7999">'.$trip->number.'</text>'
            .'<g transform="rotate(-12 330 470)"><rect x="240" y="430" width="180" height="70" rx="10" fill="none" stroke="#1E7A5C" stroke-width="5"/>'
            .'<text x="330" y="477" font-family="Tahoma, Arial" font-size="26" text-anchor="middle" fill="#1E7A5C">DEMO</text></g></svg>';

        Storage::disk('trip_files')->put($path, $svg);

        return $path;
    }
}
