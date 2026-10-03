<?php

namespace App\Services\Trips;

use App\Models\WalletEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// ══════════════════════════════════════════════════════════════════
//  El Tara — WalletLedger (the only writer of wallet_entries)
//  Location: app/Services/Trips/WalletLedger.php
//
//  A driver has three wallets (custody, collections, advances) plus
//  "pocket" (his own money the company owes back). Every movement is
//  one ledger row; a balance is the sum of its rows (Scope §6.4 —
//  "money never moves silently").
//
//  Two ways to write:
//
//    post()  — add one movement, e.g. custody issued, settlement.
//
//    syncSource() — "this record should have THESE effects on the
//              wallets". The ledger compares with what that record
//              has already posted and adds only the difference.
//              Used for expenses, transfers and collections, which
//              can change after they are recorded (an expense is
//              corrected, a transfer is approved or cancelled, a
//              collection is disputed). The old rows stay; a
//              "…_correction" row is added. Calling it twice with
//              the same targets adds nothing — safe to repeat.
//
//  Balances (Scope §10):
//    custody     = issued + transfers in − spent − moved to advances − returned
//    collections = confirmed received − transferred out − handed in
//    pocket      = own-pocket spending − refunded
//    advances    = personal advances created − repaid (repaid: Step 6)
//
//  Always called inside the caller's database transaction.
// ══════════════════════════════════════════════════════════════════

final class WalletLedger
{
    /** Differences smaller than half a piastre are rounding, not money. */
    private const EPSILON = 0.005;

    public function post(
        int $companyId,
        int $driverId,
        ?int $tripId,
        string $wallet,
        string $type,
        float $amount,
        ?Model $source,
        Actor $actor,
        ?string $note = null,
        ?\DateTimeInterface $at = null,
    ): ?WalletEntry {
        $amount = round($amount, 2);

        if (abs($amount) < self::EPSILON) {
            return null;
        }

        return WalletEntry::query()->create([
            'company_id'  => $companyId,
            'driver_id'   => $driverId,
            'trip_id'     => $tripId,
            'wallet'      => $wallet,
            'type'        => $type,
            'amount'      => $amount,
            'source_type' => $source ? class_basename($source) : null,
            'source_id'   => $source?->getKey(),
            'source_key'  => WalletEntry::sourceKey($source ? class_basename($source) : null, $source?->getKey(), $wallet, $type),
            'note'        => $note !== null ? mb_substr($note, 0, 250) : null,
            'occurred_at' => $at ?? now(),
            ...$actor->columns(),
        ]);
    }

    /**
     * Bring the wallets in line with what $source should have posted.
     *
     * @param  list<array{0:string,1:string,2:float}>  $targets  [wallet, type, amount] rows.
     *         Anything the source posted before under another wallet or
     *         type is brought back to 0 ("…_correction").
     */
    public function syncSource(Model $source, int $companyId, ?int $driverId, ?int $tripId, array $targets, Actor $actor, ?string $note = null): void
    {
        $current = WalletEntry::query()->withoutGlobalScopes()
            ->where('source_type', class_basename($source))
            ->where('source_id', $source->getKey())
            ->get();

        // The driver the source already posted to (it does not change; a
        // source with nothing posted yet takes the one given).
        $driverId = $current->first()?->driver_id ?? $driverId;

        if ($driverId === null) {
            return;
        }

        // What is there now, per wallet + type ("expense" and
        // "expense_correction" are the same line).
        $have = [];
        foreach ($current as $row) {
            $key = $row->wallet.'|'.preg_replace('/_correction$/', '', $row->type);
            $have[$key] = round(($have[$key] ?? 0) + $row->amount, 2);
        }

        $want = [];
        foreach ($targets as [$wallet, $type, $amount]) {
            $key = $wallet.'|'.$type;
            $want[$key] = round(($want[$key] ?? 0) + $amount, 2);
        }

        foreach (array_unique([...array_keys($have), ...array_keys($want)]) as $key) {
            $diff = round(($want[$key] ?? 0) - ($have[$key] ?? 0), 2);

            if (abs($diff) < self::EPSILON) {
                continue;
            }

            [$wallet, $type] = explode('|', $key, 2);
            $rowType = array_key_exists($key, $have) ? $type.'_correction' : $type;

            $this->post($companyId, $driverId, $tripId, $wallet, $rowType, $diff, $source, $actor, $note);
        }
    }

    /** One balance. Pass a trip id to get the trip's own wallet. */
    public function balance(int $driverId, string $wallet, ?int $tripId = null): float
    {
        return round((float) WalletEntry::query()
            ->where('driver_id', $driverId)
            ->where('wallet', $wallet)
            ->when($tripId, fn ($q) => $q->where('trip_id', $tripId))
            ->sum('amount'), 2);
    }

    /** All four balances of one trip: ['custody' => …, 'collections' => …, 'advances' => …, 'pocket' => …]. */
    public function tripBalances(int $tripId): array
    {
        $sums = WalletEntry::query()->where('trip_id', $tripId)
            ->selectRaw('wallet, SUM(amount) as total')->groupBy('wallet')->pluck('total', 'wallet');

        return $this->shape($sums);
    }

    /** All four balances of one driver, over all his trips. */
    public function driverBalances(int $driverId): array
    {
        $sums = WalletEntry::query()->where('driver_id', $driverId)
            ->selectRaw('wallet, SUM(amount) as total')->groupBy('wallet')->pluck('total', 'wallet');

        return $this->shape($sums);
    }

    /**
     * Balances of many drivers in ONE query (lists and the wallets screen).
     *
     * @param  list<int>|null  $driverIds  null = every driver of the company
     * @return Collection<int, array>  driver_id => balances
     */
    public function manyDriverBalances(?array $driverIds = null): Collection
    {
        return WalletEntry::query()
            ->when($driverIds !== null, fn ($q) => $q->whereIn('driver_id', $driverIds ?: [0]))
            ->selectRaw('driver_id, wallet, SUM(amount) as total')
            ->groupBy('driver_id', 'wallet')
            ->get()
            ->groupBy('driver_id')
            ->map(fn ($rows) => $this->shape($rows->pluck('total', 'wallet')));
    }

    /** Totals per ledger type for one trip — for the "issued / spent / returned" lines. */
    public function tripFlow(int $tripId): array
    {
        return WalletEntry::query()->where('trip_id', $tripId)
            ->selectRaw('wallet, type, SUM(amount) as total')->groupBy('wallet', 'type')->get()
            ->groupBy('wallet')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->type => round((float) $r->total, 2)])->all())
            ->all();
    }

    private function shape(Collection $sums): array
    {
        $out = [];
        foreach (WalletEntry::WALLETS as $wallet) {
            $out[$wallet] = round((float) ($sums[$wallet] ?? 0), 2);
        }

        return $out;
    }
}
