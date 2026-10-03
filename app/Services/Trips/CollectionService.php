<?php

namespace App\Services\Trips;

use App\Models\Trip;
use App\Models\TripCollection;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CollectionService (cash from the client, two-sided)
//  Location: app/Services/Trips/CollectionService.php
//
//  Scope §6.4 (collections wallet) and §9 (anti-fraud rule): every
//  amount a client hands to a driver is confirmed by BOTH sides.
//    record()          — by the driver (app, Step 4), the office on
//                        his behalf (Step 3), or the client (portal,
//                        Step 5). The side that records it has
//                        confirmed it.
//    confirmByDriver() — the driver confirms a client-recorded amount
//    confirmByClient() — the client confirms a driver-recorded amount
//    dispute()         — either side says "that is not right"
//    resolve()         — management decides a dispute: the amount was
//                        received ("accepted") or not ("cancelled")
//
//  The collections wallet counts an amount once the DRIVER side has
//  confirmed it and while it is not in an open dispute (Scope §9:
//  disputed amounts do not count until management resolves). The
//  ledger is brought in line after every change (sync()).
//  Amounts still waiting for the client are shown in the action
//  centre and warned about at settlement.
// ══════════════════════════════════════════════════════════════════

final class CollectionService
{
    public function __construct(private readonly WalletLedger $ledger) {}

    /**
     * @param  string  $by  driver | office | client
     */
    public function record(Trip $trip, float $amount, string $by, Actor $actor, ?string $note = null, mixed $receivedAt = null, UploadedFile|string|null $receipt = null): TripCollection
    {
        $amount = round($amount, 2);

        if (! $trip->isOpen() || $trip->status === 'planned') {
            throw TripRuleException::because('trips.not_running');
        }
        if (! $trip->driver_id) {
            throw TripRuleException::because('trips.needs_driver');
        }
        if ($amount <= 0) {
            throw TripRuleException::because('trips.amount_positive');
        }

        // Cash typed in by the office counts as "the driver confirmed" only when the company admin
        // does it (and that is marked in the audit log). For any other office user the driver must
        // confirm it in the app first — the check against a wrong or invented amount.
        $driverConfirmed = match (true) {
            $by === 'client' => false,
            $by === 'office' && $actor->user !== null && ! $actor->user->isCompanyAdmin() => false,
            default => true,
        };

        $collection = DB::transaction(function () use ($trip, $amount, $by, $actor, $note, $receivedAt, $receipt, $driverConfirmed) {
            $collection = TripCollection::query()->create([
                'company_id'          => $trip->company_id,
                'trip_id'             => $trip->id,
                'driver_id'           => $trip->driver_id,
                'customer_id'         => $trip->customer_id,
                'amount'              => $amount,
                'received_at'         => $receivedAt ?? now(),
                'recorded_by'         => $by,
                'note'                => $note ? mb_substr($note, 0, 250) : null,
                // The office records what the driver reported, so it stands
                // for the driver's side; the client still has to confirm.
                'driver_confirmed_at' => $driverConfirmed ? now() : null,
                'client_confirmed_at' => $by === 'client' ? now() : null,
                'created_by'          => $actor->userId(),
            ]);

            if ($receipt) {
                $collection->forceFill(['receipt_path' => is_string($receipt) ? $receipt : $receipt->store("trips/{$trip->id}/collections", 'trip_files')])->save();
            }

            $this->sync($collection, $actor);
            Audit::record('collection.recorded', $collection, ['after' => ['trip' => $trip->number, 'amount' => $amount, 'by' => $by, 'driver_confirmed' => $driverConfirmed, 'admin_override' => $by === 'office' && $driverConfirmed && $actor->user !== null]]);

            return $collection;
        });

        // Cash the driver took: the client is asked to confirm it (Scope §9).
        if ($by !== 'client') {
            app(Notifier::class)->toClients($trip->customer_id, 'cash.to_confirm', ['trip' => $trip->number, 'amount' => $amount], route('client.cash.index', [], false));
        }

        return $collection;
    }

    public function confirmByDriver(TripCollection $collection, Actor $actor): void
    {
        $this->change($collection, $actor, 'collection.driver_confirmed', fn () => $collection->driver_confirmed_at ??= now());
    }

    public function confirmByClient(TripCollection $collection, Actor $actor): void
    {
        $this->change($collection, $actor, 'collection.client_confirmed', fn () => $collection->client_confirmed_at ??= now());
    }

    /** @param  string  $side  driver | client */
    public function dispute(TripCollection $collection, string $side, Actor $actor, ?string $note = null): void
    {
        $this->change($collection, $actor, 'collection.disputed', function () use ($collection, $side, $note) {
            $collection->disputed_at = now();
            $collection->disputed_by = $side;
            $collection->dispute_note = $note ? mb_substr($note, 0, 250) : null;
            $collection->resolved_at = null;
            $collection->resolved_by = null;
            $collection->resolution = null;
        });
    }

    /** @param  string  $resolution  accepted (it was received) | cancelled (it was not) */
    public function resolve(TripCollection $collection, string $resolution, User $user, ?string $note = null): void
    {
        if (! $collection->isOpenDispute()) {
            throw TripRuleException::because('trips.not_disputed');
        }

        // The person who recorded the cash cannot be the one who settles a dispute about it
        // (the company admin may).
        if ($collection->recorded_by === 'office' && (int) $collection->created_by === $user->id && ! $user->isCompanyAdmin()) {
            throw TripRuleException::because('trips.own_collection');
        }

        $this->change($collection, Actor::user($user), 'collection.resolved', function () use ($collection, $resolution, $user, $note) {
            $collection->resolution = $resolution === 'accepted' ? 'accepted' : 'cancelled';
            $collection->resolved_at = now();
            $collection->resolved_by = $user->id;
            if ($note) {
                $collection->dispute_note = mb_substr(trim(($collection->dispute_note ? $collection->dispute_note.' · ' : '').$note), 0, 250);
            }
            // Management's decision stands for both sides.
            if ($collection->resolution === 'accepted') {
                $collection->driver_confirmed_at ??= now();
                $collection->client_confirmed_at ??= now();
            }
        });
    }

    /** Ledger: + amount in collections while it counts, 0 otherwise. */
    public function sync(TripCollection $collection, Actor $actor): void
    {
        $targets = $collection->counts() ? [['collections', 'collection', $collection->amount]] : [];

        $this->ledger->syncSource($collection, $collection->company_id, $collection->driver_id, $collection->trip_id, $targets, $actor, $collection->note);
    }

    private function change(TripCollection $collection, Actor $actor, string $action, callable $mutate): void
    {
        $trip = $collection->trip;

        if (! $trip->isOpen()) {
            throw TripRuleException::because('trips.trip_closed');
        }
        if ($collection->resolution === 'cancelled') {
            throw TripRuleException::because('trips.collection_cancelled');
        }

        DB::transaction(function () use ($collection, $actor, $action, $mutate) {
            $before = $collection->state();
            $mutate();
            $collection->save();
            $this->sync($collection, $actor);
            Audit::record($action, $collection, ['before' => ['state' => $before], 'after' => ['state' => $collection->state(), 'amount' => $collection->amount]]);
        });

        if ($action === 'collection.disputed') {
            $this->tellAboutDispute($collection, $trip);
        }
    }

    /** The other side and the office hear about a disputed amount (Scope §9, §11). */
    private function tellAboutDispute(TripCollection $collection, Trip $trip): void
    {
        $notifier = app(Notifier::class);
        $params = ['trip' => $trip->number, 'amount' => $collection->amount];

        if ($collection->disputed_by === 'driver') {
            $notifier->toClients($trip->customer_id, 'cash.disputed', $params, route('client.cash.index', [], false));
        }
        $notifier->toOffice($trip->company_id, 'trips.view', 'cash.disputed_office', $params + ['customer' => $trip->customer?->displayName()], route('office.trips.show', $trip, false));
    }
}
