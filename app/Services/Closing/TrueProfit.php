<?php

namespace App\Services\Closing;

use App\Models\CompanySetting;
use App\Models\MonthClose;
use App\Models\Trip;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  El Tara — TrueProfit (a trip's G&A share and true profit)
//  Location: app/Services/Closing/TrueProfit.php
//
//  Scope §6.13 and §10:
//    G&A share   = the trip's km in a month × that month's rate
//    true profit = direct profit − G&A share
//
//  Each part of the trip takes the rate of ITS OWN month:
//    · a month that is CLOSED  → the rate frozen at close (final);
//    · a month still open     → an ESTIMATE using the last closed
//                               month's rate (Scope step 6). Before any
//                               month has been closed, the company's
//                               "G&A per km estimate" setting is used.
//  The true profit is FINAL once the trip is delivered and every
//  month it touches is closed; until then it is an estimate.
//  Hired trucks carry no G&A (unless the basis is "all km").
//
//  Used by TripFigures for the trip screen. Costs two small queries
//  per trip, so it is for one trip at a time, not for long lists.
// ══════════════════════════════════════════════════════════════════

final class TrueProfit
{
    public function __construct(private readonly Allocator $allocator) {}

    /**
     * What every trip of a company needs to know, read once: the settings, the
     * rate of each closed month and the estimate rate. Pass it to forTrip() when
     * working through many trips, so they do not each ask again.
     *
     * @param  array<string,float>  $preview  month key => a rate to use for that (still open) month, e.g. while looking at it before closing
     * @return array{basis: string, rule: string, rates: array<string,float>, estimate: ?float, preview: array<string,float>}
     */
    public function context(int $companyId, array $preview = []): array
    {
        $settings = CompanySetting::for($companyId);
        $closed = MonthClose::query()->where('company_id', $companyId)->where('status', 'closed')
            ->orderByDesc('month')->get(['month', 'rate']);

        return [
            'basis'    => (string) $settings->ga_basis,
            'rule'     => in_array($settings->month_split_rule, MonthSplit::RULES, true) ? $settings->month_split_rule : 'hours',
            'rates'    => $closed->mapWithKeys(fn ($m) => [substr((string) $m->month, 0, 7) => (float) $m->rate])->all(),
            'estimate' => $closed->isNotEmpty() ? (float) $closed->first()->rate : ($settings->ga_rate_estimate !== null ? (float) $settings->ga_rate_estimate : null),
            'preview'  => $preview,
        ];
    }

    /**
     * @param  ?array  $context  from context(); read here when not given
     * @return array{
     *   applies: bool, ga_share: ?float, ga_rate: ?float, true_profit: ?float, final: bool,
     *   parts: list<array{month:string, km:float, hours:float, rate:?float, ga_share:?float, closed:bool, is_estimate:bool}>
     * }
     */
    public function forTrip(Trip $trip, float $directProfit, ?Carbon $now = null, ?array $context = null): array
    {
        $ctx = $context ?? $this->context($trip->company_id);
        $finished = in_array($trip->status, ['delivered', 'settled'], true);

        // Hired truck on the default basis: no G&A, the owner's fee is its cost.
        if ($trip->is_hired && $ctx['basis'] !== 'all_km') {
            return ['applies' => false, 'ga_share' => 0.0, 'ga_rate' => 0.0, 'true_profit' => round($directProfit, 2), 'final' => $finished, 'parts' => []];
        }

        $rateOf = $ctx['rates'];
        $estimate = $ctx['estimate'];

        $trip->loadMissing('allocations');
        $effective = $this->allocator->effective($trip, $ctx['rule'], $trip->allocations, $now);

        // Not on the road yet: the whole trip at the estimate.
        if ($effective === []) {
            $share = $estimate === null ? null : round($trip->km * $estimate, 2);

            return [
                'applies' => true, 'ga_share' => $share, 'ga_rate' => $estimate,
                'true_profit' => $share === null ? null : round($directProfit - $share, 2), 'final' => false, 'parts' => [],
            ];
        }

        $parts = [];
        $total = 0.0;
        $unknown = false;
        $allClosed = true;

        foreach ($effective as $key => $part) {
            $isClosed = $part['frozen'] || isset($rateOf[$key]);
            $rate = $part['frozen'] ? $part['rate'] : ($rateOf[$key] ?? $ctx['preview'][$key] ?? $estimate);
            $share = $part['frozen'] ? $part['ga_share'] : ($rate === null ? null : round($part['km'] * $rate, 2));

            if ($share === null) {
                $unknown = true;
            } else {
                $total += $share;
            }
            $allClosed = $allClosed && $isClosed;

            $parts[] = [
                'month' => $key, 'km' => $part['km'], 'hours' => $part['hours'], 'rate' => $rate, 'ga_share' => $share,
                'closed' => $isClosed, 'is_estimate' => $part['is_estimate'],
            ];
        }

        $total = $unknown ? null : round($total, 2);
        $lastRate = $parts ? end($parts)['rate'] : $estimate;

        return [
            'applies'     => true,
            'ga_share'    => $total,
            'ga_rate'     => $lastRate,
            'true_profit' => $total === null ? null : round($directProfit - $total, 2),
            'final'       => $finished && $allClosed && ! $unknown,
            'parts'       => $parts,
        ];
    }
}
