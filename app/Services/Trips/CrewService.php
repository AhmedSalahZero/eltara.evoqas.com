<?php

namespace App\Services\Trips;

use App\Models\ExpenseCategory;
use App\Models\Trip;
use App\Models\TripCrew;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CrewService (second driver, assistant)
//  Location: app/Services/Trips/CrewService.php
//
//  A trip has ONE main driver, who holds all the money. A second driver
//  (long trips) or an assistant is only recorded, with his allowance.
//    · The allowance is paid by the main driver, so it becomes an ordinary
//      trip expense (category "allowance") from his custody; a hired truck
//      has no custody, so the company pays it. It is posted when loading
//      starts (custody is in his hands by then), or at once if the trip
//      has already started.
//    · His allowance is added to the trip's standard allowance budget, so
//      the budget check does not flag it as overspending.
//    · Nothing can change once the trip is settled or cancelled.
//  Permission: trips.edit to list people; an allowance also needs
//  trip_expenses.create, because it creates a cost.
// ══════════════════════════════════════════════════════════════════

final class CrewService
{
    public const MAX = 4;

    public function __construct(private readonly ExpenseService $expenses) {}

    /** @param array{role?:string, name?:string, allowance?:mixed, note?:?string} $data */
    public function add(Trip $trip, array $data, User $user): TripCrew
    {
        $this->assertOpen($trip);
        $clean = $this->clean($data, $user);

        if (TripCrew::query()->where('trip_id', $trip->id)->count() >= self::MAX) {
            throw TripRuleException::because('crew.too_many', ['max' => self::MAX]);
        }

        return DB::transaction(function () use ($trip, $clean, $user) {
            $crew = TripCrew::query()->create($clean + [
                'company_id' => $trip->company_id,
                'trip_id' => $trip->id,
                'created_by' => $user->id,
            ]);

            $this->sync($crew, $trip, Actor::user($user));
            Audit::record('crew.created', $crew, ['after' => $this->view($crew, $trip)]);

            return $crew;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(TripCrew $crew, array $data, User $user): TripCrew
    {
        $trip = $crew->trip()->firstOrFail();
        $this->assertOpen($trip);
        $clean = $this->clean($data, $user);

        return DB::transaction(function () use ($crew, $trip, $clean, $user) {
            $before = $this->view($crew, $trip);
            $crew->fill($clean)->save();

            $this->sync($crew, $trip, Actor::user($user));
            Audit::record('crew.updated', $crew, ['before' => $before, 'after' => $this->view($crew, $trip)]);

            return $crew;
        });
    }

    public function remove(TripCrew $crew, User $user): void
    {
        $trip = $crew->trip()->firstOrFail();
        $this->assertOpen($trip);

        DB::transaction(function () use ($crew, $trip, $user) {
            Audit::record('crew.deleted', $crew, ['before' => $this->view($crew, $trip)]);

            if ($crew->expense) {
                $this->expenses->delete($crew->expense, Actor::user($user));
            }
            $this->budget($trip, -$crew->budget_added);
            $crew->delete();
        });
    }

    /** Loading has started: pay out the allowances that are still waiting. */
    public function postDue(Trip $trip, Actor $actor): void
    {
        foreach (TripCrew::query()->where('trip_id', $trip->id)->whereNull('trip_expense_id')->where('allowance', '>', 0)->get() as $crew) {
            $this->sync($crew, $trip, $actor);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** The expense, the budget and the person always match. */
    private function sync(TripCrew $crew, Trip $trip, Actor $actor): void
    {
        $crew->refresh();
        $amount = round((float) $crew->allowance, 2);

        $this->budget($trip, $amount - (float) $crew->budget_added);
        $crew->forceFill(['budget_added' => $this->budgetApplied($trip) ? $amount : $crew->budget_added])->save();

        if (! $this->started($trip)) {
            return;
        }

        $expense = $crew->expense;

        if ($amount <= 0) {
            if ($expense) {
                $this->expenses->delete($expense, $actor);
            }

            return;
        }

        $data = [
            'expense_category_id' => ExpenseCategory::query()->where('code', 'allow')->value('id'),
            'paid_from' => $trip->is_hired || ! $trip->driver_id ? 'company' : 'custody',
            'amount' => $amount,
            'note' => __('crew.expense_note', ['role' => __('crew.roles.'.$crew->role), 'name' => $crew->name]),
            'spent_at' => $trip->loading_started_at ?? now(),
        ];

        if ($expense) {
            $this->expenses->update($expense, $data, $actor);

            return;
        }

        $expense = $this->expenses->record($trip, $data, $actor);
        $crew->forceFill(['trip_expense_id' => $expense->id])->save();
    }

    /** Only a trip that already has a standard budget gets one more allowance in it. */
    private function budgetApplied(Trip $trip): bool
    {
        return collect($trip->standard_budget ?? [])->sum() > 0;
    }

    private function budget(Trip $trip, float $delta): void
    {
        if (abs($delta) < 0.005 || ! $this->budgetApplied($trip)) {
            return;
        }

        $id = ExpenseCategory::query()->where('code', 'allow')->value('id');
        if (! $id) {
            return;
        }

        $standard = $trip->standard_budget ?? [];
        $standard[(string) $id] = round(max(0.0, (float) ($standard[(string) $id] ?? 0) + $delta), 2);
        $trip->forceFill(['standard_budget' => $standard])->save();
    }

    private function started(Trip $trip): bool
    {
        return in_array($trip->status, ['loading', 'on_road', 'delivered'], true);
    }

    private function assertOpen(Trip $trip): void
    {
        if (! $trip->isOpen()) {
            throw TripRuleException::because('trips.trip_closed');
        }
    }

    /** @return array{role:string, name:string, allowance:float, note:?string} */
    private function clean(array $data, User $user): array
    {
        $role = (string) ($data['role'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));
        $raw = $data['allowance'] ?? null;
        $allowance = $raw === null || $raw === '' ? 0.0 : (is_numeric($raw) ? round((float) $raw, 2) : -1.0);

        if (! in_array($role, TripCrew::ROLES, true)) {
            throw TripRuleException::because('crew.bad_role');
        }
        if ($name === '') {
            throw TripRuleException::because('crew.name_required');
        }
        if ($allowance < 0 || $allowance > 100000) {
            throw TripRuleException::because('crew.bad_allowance');
        }
        if ($allowance > 0 && ! $user->can('trip_expenses.create')) {
            throw TripRuleException::because('crew.needs_permission');
        }

        return ['role' => $role, 'name' => mb_substr($name, 0, 80), 'allowance' => $allowance, 'note' => $note === '' ? null : mb_substr($note, 0, 250)];
    }

    /** @return array<string,mixed> */
    private function view(TripCrew $crew, Trip $trip): array
    {
        return ['trip' => $trip->number, 'role' => $crew->role, 'name' => $crew->name, 'allowance' => $crew->allowance];
    }
}
