<?php

namespace App\Services\Trips;

use App\Models\CompanySetting;
use App\Models\ExpenseCategory;
use App\Models\MonthClose;
use App\Models\Trip;
use App\Models\TripCharge;
use App\Models\TripExpense;
use App\Services\Closing\TrueProfit;
use Illuminate\Database\Eloquent\Builder;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TripFigures (the numbers a trip screen shows)
//  Location: app/Services/Trips/TripFigures.php
//
//  Scope §10, for one trip:
//    revenue        = freight price + extra charges − deductions
//    direct cost    = all expense lines, incl. the hired-truck fee
//                     (personal spending is NOT a cost — it is an advance)
//    direct profit  = revenue − direct cost, margin = profit ÷ revenue
//    per km         = revenue, cost and profit ÷ the trip's km
//    G&A share      = km × G&A rate (hired trucks = 0)
//    true profit    = direct profit − G&A share
//  Step 6: the G&A share comes from App\Services\Closing\TrueProfit —
//  each month the trip touches takes its own month's rate (closed =
//  final, open = estimate from the last closed month's rate; before
//  any month is closed, the company's "G&A per km estimate" setting).
//  A trip spanning two months shows both parts.
//
//  Budget vs actual per expense category: a category more than the
//  company's "over-budget flag %" above its standard is flagged
//  (Company settings; 15% by default, as in Scope §6.6).
//
//  withMoney() adds revenue and cost to a LIST query in one go
//  (sub-queries), so a page of 25 trips costs one query, not 50.
// ══════════════════════════════════════════════════════════════════

final class TripFigures
{
    /** Scope §6.6: 15% unless the company set its own % in Company settings. */
    public const DEFAULT_OVER_BUDGET_PERCENT = 15.0;

    public function __construct(private readonly WalletLedger $ledger, private readonly TrueProfit $trueProfit) {}

    public function forTrip(Trip $trip): array
    {
        $trip->loadMissing('charges', 'expenses');

        $extras = round((float) $trip->charges->where('kind', 'extra')->sum('amount'), 2);
        $deductions = round((float) $trip->charges->where('kind', 'deduction')->sum('amount'), 2);
        $revenue = round($trip->freight_price + $extras - $deductions, 2);
        $cost = round((float) $trip->expenses->where('is_personal', false)->sum('amount'), 2);

        return [
            'freight'    => $trip->freight_price,
            'extras'     => $extras,
            'deductions' => $deductions,
            ...$this->profit($trip, $revenue, $cost),
            'budget'     => $this->budget($trip),
        ];
    }

    /** Revenue, cost, profit, margin, per km, G&A and true profit. */
    public function profit(Trip $trip, float $revenue, float $cost): array
    {
        $km = max(1, (int) $trip->km);
        $profit = round($revenue - $cost, 2);
        $ga = $this->trueProfit->forTrip($trip, $profit);

        return [
            'revenue'        => $revenue,
            'cost'           => $cost,
            'profit'         => $profit,
            'margin'         => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            'revenue_per_km' => round($revenue / $km, 2),
            'cost_per_km'    => round($cost / $km, 2),
            'profit_per_km'  => round($profit / $km, 2),
            'ga_rate'        => $ga['ga_rate'],
            'ga_share'       => $ga['ga_share'] ?? 0.0,
            'ga_known'       => $ga['ga_share'] !== null,
            'ga_parts'       => $ga['parts'],
            'true_profit'    => $ga['true_profit'],
            'true_estimate'  => ! $ga['final'],
        ];
    }

    /** The company's over-budget flag, as a percent (15 = flag above 115% of standard). */
    public static function overBudgetPercent(int $companyId): float
    {
        return (float) (CompanySetting::for($companyId)->over_budget_percent ?? self::DEFAULT_OVER_BUDGET_PERCENT);
    }

    /** Standard vs actual per category, flagged above the company's %. */
    public function budget(Trip $trip): array
    {
        $factor = 1 + self::overBudgetPercent($trip->company_id) / 100;
        $standard = collect($trip->standard_budget ?? [])->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v]);
        $actual = $trip->expenses->where('is_personal', false)->groupBy('expense_category_id')->map(fn ($rows) => round((float) $rows->sum('amount'), 2));

        $ids = $standard->keys()->merge($actual->keys())->unique()->filter()->values();
        $categories = ExpenseCategory::query()->whereIn('id', $ids)->ordered()->get();

        // A trip with a standard budget flags spending on a category that has NO standard at all
        // (the owner's hire fee is the one exception: it is a price, not a road cost).
        $hasBudget = $standard->sum() > 0;

        $rows = $categories->map(function (ExpenseCategory $c) use ($standard, $actual, $factor, $hasBudget) {
            $std = $standard[$c->id] ?? 0.0;
            $act = $actual[$c->id] ?? 0.0;
            $unbudgeted = $hasBudget && $std <= 0 && $act > 0 && $c->code !== 'hire';

            return [
                'category_id' => $c->id,
                'name'        => $c->displayName(),
                'icon'        => $c->icon,
                'standard'    => $std,
                'actual'      => $act,
                'variance'    => round($act - $std, 2),
                'over'        => ($std > 0 && $act > $std * $factor + 0.001) || $unbudgeted,
                'unbudgeted'  => $unbudgeted,
            ];
        })->values();

        $stdTotal = round($standard->sum(), 2);
        $actTotal = round($actual->sum(), 2);

        return [
            'rows'     => $rows->all(),
            'percent'  => round(($factor - 1) * 100, 2),
            'standard' => $stdTotal,
            'actual'   => $actTotal,
            'variance' => round($actTotal - $stdTotal, 2),
            'over'     => $rows->contains('over', true) || ($stdTotal > 0 && $actTotal > $stdTotal * $factor + 0.001),
        ];
    }

    /**
     * The trip's wallets as the "issued / transfers in / spent / returned"
     * lines of the wallet cards (Scope §6.3).
     */
    public function wallets(Trip $trip): array
    {
        $flow = $trip->driver_id ? $this->ledger->tripFlow($trip->id) : [];
        $sum = fn (string $wallet, array $types) => round(array_sum(array_map(
            fn ($t) => ($flow[$wallet][$t] ?? 0) + ($flow[$wallet][$t.'_correction'] ?? 0), $types)), 2);

        $custody = [
            'issued'       => $sum('custody', ['custody_issued']),
            'transfers_in' => $sum('custody', ['transfer_in']),
            'spent'        => -$sum('custody', ['expense']),
            'to_advances'  => -$sum('custody', ['personal_to_advance']),
            'returned'     => -$sum('custody', ['custody_returned', 'custody_refunded']),
        ];
        $collections = [
            'received'        => $sum('collections', ['collection']),
            'transferred_out' => -$sum('collections', ['transfer_out']),
            'handed_in'       => -$sum('collections', ['collections_handed_in']),
        ];
        $pocket = [
            'spent'    => $sum('pocket', ['own_pocket']),
            'refunded' => -$sum('pocket', ['pocket_refunded']),
        ];

        $balance = fn (string $wallet) => round(array_sum(array_map(fn ($v) => $v, $flow[$wallet] ?? [])), 2);

        return [
            'custody'     => $custody + ['balance' => $balance('custody')],
            'collections' => $collections + ['balance' => $balance('collections')],
            'pocket'      => $pocket + ['balance' => $balance('pocket')],
            'advances'    => ['created' => $balance('advances')],
        ];
    }

    /** The G&A rate per km used for estimates: the last closed month's, else the company's setting, else null. */
    public static function gaRate(int $companyId): ?float
    {
        $closed = MonthClose::query()->where('company_id', $companyId)->where('status', 'closed')->orderByDesc('month')->value('rate');
        if ($closed !== null) {
            return (float) $closed;
        }

        $estimate = CompanySetting::for($companyId)->ga_rate_estimate;

        return $estimate !== null ? (float) $estimate : null;
    }

    /** Adds revenue_total and cost_total to a trips list query (sub-queries). */
    public static function withMoney(Builder $query): Builder
    {
        $charges = TripCharge::query()->withoutGlobalScopes()
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'deduction' THEN -amount ELSE amount END), 0)")
            ->whereColumn('trip_charges.trip_id', 'trips.id');

        $cost = TripExpense::query()->withoutGlobalScopes()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('trip_expenses.trip_id', 'trips.id')
            ->where('is_personal', false);

        return $query->addSelect([
            'charges_total' => $charges,
            'cost_total'    => $cost,
        ]);
    }

    /** Revenue of a row loaded with withMoney(). */
    public static function rowRevenue(Trip $trip): float
    {
        return round($trip->freight_price + (float) $trip->charges_total, 2);
    }
}
