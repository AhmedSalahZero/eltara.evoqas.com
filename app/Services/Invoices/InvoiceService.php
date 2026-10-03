<?php

namespace App\Services\Invoices;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Trip;
use App\Models\TripCharge;
use App\Models\User;
use App\Services\Trips\TripRuleException;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — InvoiceService (linking invoice numbers to trips)
//  Location: app/Services/Invoices/InvoiceService.php
//
//  Scope §6.11. Invoices are issued in the company's ERP / accounting
//  system. El Tara only LINKS the invoice number to trips.
//    · one invoice can cover several trips of the SAME customer
//      (5 trucks on one order);
//    · only SETTLED ("closed") trips can be linked — that is the last
//      step of the trip's life (Scope §6.3);
//    · a trip belongs to at most one invoice;
//    · an invoice number is unique in the company. Linking more trips
//      to a number that already exists adds them to it (same customer).
//  stats() feeds the screen's tiles and the dashboard's "closed trips
//  without an invoice number" (Step 7): how many, their value, and
//  how long the oldest has waited.
//  Every link / change / removal is audited.
// ══════════════════════════════════════════════════════════════════

final class InvoiceService
{
    /** @param list<int> $tripIds */
    public function link(int $customerId, string $number, ?string $issuedOn, ?string $note, array $tripIds, User $user): Invoice
    {
        $number = $this->number($number);
        $tripIds = array_values(array_unique(array_map('intval', $tripIds)));

        if ($tripIds === []) {
            throw TripRuleException::because('finance.inv.pick_trips');
        }

        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw TripRuleException::because('finance.inv.customer_required');
        }

        return DB::transaction(function () use ($customer, $number, $issuedOn, $note, $tripIds, $user) {
            $invoice = Invoice::query()->where('number', $number)->lockForUpdate()->first();

            if ($invoice && (int) $invoice->customer_id !== (int) $customer->id) {
                throw TripRuleException::because('finance.inv.number_other_customer');
            }

            $created = ! $invoice;
            $invoice ??= Invoice::query()->create([
                'company_id'  => $customer->company_id,
                'customer_id' => $customer->id,
                'number'      => $number,
                'issued_on'   => $issuedOn ?: null,
                'note'        => $this->text($note),
                'created_by'  => $user->id,
            ]);

            if (! $created && ($issuedOn || $note)) {
                $invoice->fill(array_filter(['issued_on' => $issuedOn ?: null, 'note' => $this->text($note)], fn ($v) => $v !== null))->save();
            }

            $this->attach($invoice, $tripIds);

            Audit::record('invoice.linked', $invoice, ['after' => ['number' => $number, 'customer' => $customer->name_en ?: $customer->name_ar, 'trips' => $tripIds, 'new_invoice' => $created]]);

            return $invoice;
        });
    }

    /**
     * @param  array{number?:string, issued_on?:?string, note?:?string, add?:list<int>, remove?:list<int>}  $data
     */
    public function update(Invoice $invoice, array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $user) {
            $before = ['number' => $invoice->number, 'issued_on' => $invoice->issued_on, 'trips' => Trip::query()->where('invoice_id', $invoice->id)->pluck('id')->all()];

            if (isset($data['number']) && $this->number($data['number']) !== $invoice->number) {
                $new = $this->number($data['number']);
                if (Invoice::query()->where('number', $new)->whereKeyNot($invoice->id)->exists()) {
                    throw TripRuleException::because('finance.inv.number_taken');
                }
                $invoice->number = $new;
            }

            if (array_key_exists('issued_on', $data)) {
                $invoice->issued_on = $data['issued_on'] ?: null;
            }
            if (array_key_exists('note', $data)) {
                $invoice->note = $this->text($data['note']);
            }
            $invoice->save();

            $remove = array_values(array_unique(array_map('intval', $data['remove'] ?? [])));
            if ($remove !== []) {
                Trip::query()->where('invoice_id', $invoice->id)->whereIn('id', $remove)->update(['invoice_id' => null]);
            }

            $add = array_values(array_unique(array_map('intval', $data['add'] ?? [])));
            if ($add !== []) {
                $this->attach($invoice, $add);
            }

            if (! Trip::query()->where('invoice_id', $invoice->id)->exists()) {
                throw TripRuleException::because('finance.inv.needs_a_trip');
            }

            Audit::record('invoice.updated', $invoice, ['before' => $before, 'after' => [
                'number' => $invoice->number, 'issued_on' => $invoice->issued_on, 'trips' => Trip::query()->where('invoice_id', $invoice->id)->pluck('id')->all(),
            ]]);

            return $invoice;
        });
    }

    /** Removes the link (the trips become "not invoiced" again). */
    public function delete(Invoice $invoice, User $user): void
    {
        DB::transaction(function () use ($invoice, $user) {
            $trips = Trip::query()->where('invoice_id', $invoice->id)->pluck('id')->all();
            Trip::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            Audit::record('invoice.deleted', $invoice, ['before' => ['number' => $invoice->number, 'trips' => $trips]]);
            $invoice->delete();
        });
    }

    /** Settled trips of a customer with no invoice yet — what can still be linked. */
    public function eligible(int $customerId): Builder
    {
        return Trip::query()->where('trips.customer_id', $customerId)->where('trips.status', 'settled')->whereNull('trips.invoice_id');
    }

    /**
     * The screen's tiles.
     *
     * @return array{invoices:int, linked_trips:int, linked_value:float, open_count:int, open_value:float, oldest_days:?int, oldest_trip:?array}
     */
    public function stats(): array
    {
        $open = Trip::query()->where('trips.status', 'settled')->whereNull('trips.invoice_id');
        $linked = Trip::query()->whereNotNull('trips.invoice_id');

        $oldest = (clone $open)->orderBy('trips.settled_at')->orderBy('trips.id')->first(['trips.id', 'trips.number', 'trips.settled_at']);

        return [
            'invoices'     => Invoice::query()->count(),
            'linked_trips' => (clone $linked)->count(),
            'linked_value' => $this->value($linked),
            'open_count'   => (clone $open)->count(),
            'open_value'   => $this->value($open),
            'oldest_days'  => $oldest?->settled_at ? (int) $oldest->settled_at->diffInDays(Carbon::now(), true) : null,
            'oldest_trip'  => $oldest ? ['id' => $oldest->id, 'number' => $oldest->number] : null,
        ];
    }

    /** Revenue (freight + extra charges − deductions) of the trips in a query. */
    public function value(Builder $trips): float
    {
        $ids = (clone $trips)->select('trips.id');
        $freight = (float) Trip::query()->whereIn('trips.id', (clone $ids))->sum('trips.freight_price');
        $charges = (float) TripCharge::query()->whereIn('trip_id', (clone $ids))
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'deduction' THEN -amount ELSE amount END), 0) as t")->value('t');

        return round($freight + $charges, 2);
    }

    // ── Internals ──────────────────────────────────────────────────

    /** @param list<int> $tripIds  linked to the invoice; each must be settled, of the invoice's customer and not on another invoice */
    private function attach(Invoice $invoice, array $tripIds): void
    {
        $trips = Trip::query()->whereIn('id', $tripIds)->lockForUpdate()->get();

        if ($trips->count() !== count($tripIds)) {
            throw TripRuleException::because('finance.inv.trip_not_found');
        }

        foreach ($trips as $trip) {
            if ((int) $trip->customer_id !== (int) $invoice->customer_id) {
                throw TripRuleException::because('finance.inv.other_customer', ['trip' => $trip->number]);
            }
            if ($trip->status !== 'settled') {
                throw TripRuleException::because('finance.inv.not_settled', ['trip' => $trip->number]);
            }
            if ($trip->invoice_id !== null && (int) $trip->invoice_id !== (int) $invoice->id) {
                throw TripRuleException::because('finance.inv.already_linked', ['trip' => $trip->number]);
            }
        }

        Trip::query()->whereIn('id', $tripIds)->update(['invoice_id' => $invoice->id]);
    }

    private function number(string $number): string
    {
        $number = trim(preg_replace('/\s+/u', ' ', $number) ?? '');

        if ($number === '') {
            throw TripRuleException::because('finance.inv.number_required');
        }

        return mb_substr($number, 0, 60);
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 250);
    }
}
