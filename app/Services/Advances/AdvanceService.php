<?php

namespace App\Services\Advances;

use App\Models\Driver;
use App\Models\DriverAdvance;
use App\Models\DriverAdvanceRepayment;
use App\Models\User;
use App\Services\Trips\Actor;
use App\Services\Trips\TripRuleException;
use App\Services\Trips\WalletLedger;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — AdvanceService (personal advances / سلف)
//  Location: app/Services/Advances/AdvanceService.php
//
//  Scope §6.12. A personal advance: amount, reason, monthly
//  instalment, amount repaid, remaining balance.
//    · advances made automatically from personal spending on a trip
//      already exist (source "trip_personal", Step 3) — they appear
//      here too, marked;
//    · the office can also give a MANUAL advance;
//    · money comes back by PAYROLL DEDUCTION (each month, each
//      advance once) or as a CASH repayment.
//  Every change is a row in the driver's advances wallet (the ledger),
//  so the wallet always equals what the drivers still owe.
//
//  MONTHLY DEDUCTION  an advance with an instalment is deducted
//  instalment by instalment (never more than what is left); an
//  advance WITHOUT an instalment is deducted in full at the next
//  payroll. Advances given after the month ends wait for the next one.
//  due() / payroll() build the payroll sheet; applyPayroll() records
//  the deductions (once per advance per month — also enforced by a
//  unique key in the database, so two people pressing the button
//  cannot deduct twice).
// ══════════════════════════════════════════════════════════════════

final class AdvanceService
{
    public const MAX_AMOUNT = 1000000;

    public function __construct(private readonly WalletLedger $ledger) {}

    public function create(Driver $driver, float $amount, ?string $reason, ?float $instalment, User $user): DriverAdvance
    {
        $amount = round($amount, 2);
        $this->assertAmounts($amount, $instalment);

        return DB::transaction(function () use ($driver, $amount, $reason, $instalment, $user) {
            $advance = DriverAdvance::query()->create([
                'company_id'         => $driver->company_id,
                'driver_id'          => $driver->id,
                'amount'             => $amount,
                'reason'             => $this->text($reason),
                'monthly_instalment' => $instalment !== null && $instalment > 0 ? round($instalment, 2) : null,
                'repaid_amount'      => 0,
                'source'             => 'manual',
                'status'             => 'open',
                'created_by'         => $user->id,
            ]);

            $this->ledger->post($driver->company_id, $driver->id, null, 'advances', 'advance_created', $amount, $advance, Actor::user($user), $advance->reason);
            Audit::record('advance.created', $advance, ['after' => ['driver' => $driver->name, 'amount' => $amount, 'instalment' => $advance->monthly_instalment, 'reason' => $advance->reason]]);

            return $advance;
        });
    }

    /** Changes the reason and the monthly instalment of an open advance. */
    public function update(DriverAdvance $advance, ?string $reason, ?float $instalment, User $user): DriverAdvance
    {
        $this->assertOpen($advance);
        $this->assertAmounts((float) $advance->amount, $instalment);

        $before = ['reason' => $advance->reason, 'instalment' => $advance->monthly_instalment];
        $advance->fill([
            'reason'             => $this->text($reason),
            'monthly_instalment' => $instalment !== null && $instalment > 0 ? round($instalment, 2) : null,
        ])->save();

        Audit::record('advance.updated', $advance, ['before' => $before, 'after' => ['reason' => $advance->reason, 'instalment' => $advance->monthly_instalment]]);

        return $advance;
    }

    /** The driver paid some of it back by hand. */
    public function repayCash(DriverAdvance $advance, float $amount, ?string $note, User $user): DriverAdvanceRepayment
    {
        return DB::transaction(fn () => $this->repay($advance, round($amount, 2), 'cash', null, $note, $user));
    }

    /** Only a MANUAL advance nothing has been paid back on can be cancelled (a trip's personal spending is corrected on the trip). */
    public function cancel(DriverAdvance $advance, User $user): void
    {
        DB::transaction(function () use ($advance, $user) {
            $advance = DriverAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if ($advance->source !== 'manual') {
                throw TripRuleException::because('finance.adv.cancel_trip_advance');
            }
            if ($advance->status !== 'open' || $advance->repaid_amount > 0 || $advance->repayments()->exists()) {
                throw TripRuleException::because('finance.adv.cancel_repaid');
            }

            $advance->forceFill(['status' => 'cancelled'])->save();
            $this->ledger->syncSource($advance, $advance->company_id, $advance->driver_id, null, [], Actor::user($user), $advance->reason);
            Audit::record('advance.cancelled', $advance, ['before' => ['amount' => $advance->amount, 'reason' => $advance->reason]]);
        });
    }

    // ── Payroll ────────────────────────────────────────────────────

    /** "2026-09" → the month's first day; any other value (or a future month) → this month. */
    public static function parseMonth(?string $key): Carbon
    {
        $current = Carbon::now()->startOfMonth();

        if (! is_string($key) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key)) {
            return $current;
        }

        $month = Carbon::createFromFormat('Y-m-d H:i:s', $key.'-01 00:00:00')->startOfMonth();

        return $month->greaterThan($current) ? $current : $month;
    }

    /**
     * The payroll sheet of a month: per driver, what is deducted (or still to deduct).
     *
     * @return list<array{driver_id:int, name:string, mobile:?string, base_salary:?float, due:float, applied:float, remaining_after:float, advances:list<array>}>
     */
    public function payroll(Carbon $month): array
    {
        $db = $month->format('Y-m-01');
        $next = $month->copy()->addMonth()->format('Y-m-d H:i:s');

        $applied = DriverAdvanceRepayment::query()->where('method', 'payroll')->where('deduction_month', $db)->get()->keyBy('driver_advance_id');

        $advances = DriverAdvance::query()
            ->with('driver:id,name,mobile,base_salary')
            ->where('created_at', '<', $next)
            ->where(fn ($q) => $q->where('status', 'open')->orWhereIn('id', $applied->keys()->all() ?: [0]))
            ->whereIn('status', ['open', 'repaid'])
            ->orderBy('driver_id')->orderBy('id')->get();

        $byDriver = [];
        foreach ($advances as $a) {
            $done = $applied->get($a->id);
            $due = $done ? 0.0 : $this->instalmentDue($a);

            if (! $done && $due <= 0) {
                continue;
            }

            $row = &$byDriver[$a->driver_id];
            $row ??= [
                'driver_id' => $a->driver_id, 'name' => $a->driver?->name ?? '—', 'mobile' => $a->driver?->mobile,
                'base_salary' => $a->driver?->base_salary !== null ? (float) $a->driver->base_salary : null,
                'due' => 0.0, 'applied' => 0.0, 'remaining_after' => 0.0, 'advances' => [],
            ];
            $row['due'] = round($row['due'] + $due, 2);
            $row['applied'] = round($row['applied'] + ($done ? (float) $done->amount : 0.0), 2);
            $row['remaining_after'] = round($row['remaining_after'] + $a->remaining() - $due, 2);
            $row['advances'][] = [
                'id' => $a->id, 'reason' => $a->reason, 'source' => $a->source, 'due' => $due, 'applied' => $done ? (float) $done->amount : null,
                'instalment' => $a->monthly_instalment, 'remaining' => $a->remaining(), 'no_instalment' => $a->monthly_instalment === null,
            ];
            unset($row);
        }

        usort($byDriver, fn ($x, $y) => strcmp((string) $x['name'], (string) $y['name']));

        return array_values($byDriver);
    }

    /**
     * Records the deductions of a month (each advance once). Returns how many and how much.
     *
     * @param  list<int>|null  $driverIds  only these drivers (null = everyone with something due)
     * @return array{count:int, total:float}
     */
    public function applyPayroll(Carbon $month, User $user, ?array $driverIds = null): array
    {
        $count = 0;
        $total = 0.0;

        $due = DriverAdvance::query()
            ->where('status', 'open')->where('created_at', '<', $month->copy()->addMonth()->format('Y-m-d H:i:s'))
            ->when($driverIds !== null, fn ($q) => $q->whereIn('driver_id', $driverIds ?: [0]))
            ->orderBy('id')->pluck('id');

        foreach ($due as $id) {
            try {
                DB::transaction(function () use ($id, $month, $user, &$count, &$total) {
                    $advance = DriverAdvance::query()->lockForUpdate()->find($id);
                    if (! $advance || $advance->status !== 'open') {
                        return;
                    }

                    $already = DriverAdvanceRepayment::query()->where('driver_advance_id', $id)->where('method', 'payroll')->where('deduction_month', $month->format('Y-m-01'))->exists();
                    $amount = $this->instalmentDue($advance);
                    if ($already || $amount <= 0) {
                        return;
                    }

                    $this->repay($advance, $amount, 'payroll', $month, null, $user);
                    $count++;
                    $total += $amount;
                });
            } catch (UniqueConstraintViolationException) {
                // someone else deducted this one a moment ago — nothing to do
            }
        }

        if ($count > 0) {
            Audit::record('advance.payroll_applied', null, ['month' => $month->format('Y-m'), 'advances' => $count, 'total' => round($total, 2)], $user->company_id);
        }

        return ['count' => $count, 'total' => round($total, 2)];
    }

    /** What this advance would give back at the next payroll. */
    public function instalmentDue(DriverAdvance $advance): float
    {
        $remaining = $advance->remaining();
        if ($remaining <= 0) {
            return 0.0;
        }

        return round($advance->monthly_instalment ? min((float) $advance->monthly_instalment, $remaining) : $remaining, 2);
    }

    // ── Internals ──────────────────────────────────────────────────

    /** Always called inside a transaction, with the advance row locked. */
    private function repay(DriverAdvance $advance, float $amount, string $method, ?Carbon $month, ?string $note, User $user): DriverAdvanceRepayment
    {
        $advance = DriverAdvance::query()->lockForUpdate()->findOrFail($advance->id);
        $this->assertOpen($advance);

        if ($amount <= 0) {
            throw TripRuleException::because('finance.adv.amount_positive');
        }
        if ($amount > $advance->remaining() + 0.001) {
            throw TripRuleException::because('finance.adv.more_than_remaining', ['amount' => number_format($advance->remaining(), 2)]);
        }

        $repayment = DriverAdvanceRepayment::query()->create([
            'company_id'        => $advance->company_id,
            'driver_advance_id' => $advance->id,
            'driver_id'         => $advance->driver_id,
            'amount'            => $amount,
            'method'            => $method,
            'deduction_month'   => $month?->format('Y-m-01'),
            'note'              => $this->text($note),
            'created_by'        => $user->id,
        ]);

        $advance->repaid_amount = round($advance->repaid_amount + $amount, 2);
        if ($advance->remaining() <= 0.005) {
            $advance->status = 'repaid';
        }
        $advance->save();

        $label = $method === 'payroll' ? __('finance.adv.ledger_payroll', ['month' => $month?->format('Y-m')]) : __('finance.adv.ledger_cash');
        $this->ledger->post($advance->company_id, $advance->driver_id, null, 'advances', 'advance_repaid', -$amount, $repayment, Actor::user($user), $label);

        Audit::record('advance.repaid', $advance, ['amount' => $amount, 'method' => $method, 'month' => $month?->format('Y-m'), 'remaining' => $advance->remaining()]);

        return $repayment;
    }

    private function assertOpen(DriverAdvance $advance): void
    {
        if ($advance->status !== 'open') {
            throw TripRuleException::because('finance.adv.not_open');
        }
    }

    private function assertAmounts(float $amount, ?float $instalment): void
    {
        if ($amount <= 0) {
            throw TripRuleException::because('finance.adv.amount_positive');
        }
        if ($amount > self::MAX_AMOUNT) {
            throw TripRuleException::because('finance.adv.amount_too_big', ['max' => number_format(self::MAX_AMOUNT)]);
        }
        if ($instalment !== null && $instalment < 0) {
            throw TripRuleException::because('finance.adv.instalment_bad');
        }
        if ($instalment !== null && $instalment > $amount + 0.001) {
            throw TripRuleException::because('finance.adv.instalment_over_amount');
        }
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 250);
    }
}
