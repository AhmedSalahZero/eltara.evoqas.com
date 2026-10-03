<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\WalletEntry;
use App\Services\Trips\Actor;
use App\Services\Trips\WalletLedger;
use App\Support\Audit;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: database-level protection of the ledger and audit log
//  Location: tests/Feature/LedgerAndAuditHardeningTest.php
//  Audit report items Q17 (immutable at database level), Q18 (sign-ins
//  in the audit log), Q19 (no double posting). Q20 (status CHECKs) is a
//  MySQL-only feature and is not testable on the SQLite test database.
// ══════════════════════════════════════════════════════════════════

class LedgerAndAuditHardeningTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    /** Runs $change and returns the database error it caused (or null if the database allowed it). */
    private function refusedByDatabase(callable $change): ?QueryException
    {
        try {
            $change();
        } catch (QueryException $e) {
            return $e;
        }

        return null;
    }

    // ── Q17 ───────────────────────────────────────────────────────

    public function test_the_database_itself_refuses_to_change_or_delete_a_ledger_row(): void
    {
        $this->runningTrip();
        $id = (int) WalletEntry::query()->withoutGlobalScopes()->value('id');
        $before = (float) DB::table('wallet_entries')->where('id', $id)->value('amount');

        $this->assertNotNull($this->refusedByDatabase(fn () => DB::table('wallet_entries')->where('id', $id)->update(['amount' => 1])));
        $this->assertNotNull($this->refusedByDatabase(fn () => DB::table('wallet_entries')->where('id', $id)->delete()));
        $this->assertNotNull($this->refusedByDatabase(fn () => DB::table('wallet_entries')->delete()));

        $this->assertEquals($before, (float) DB::table('wallet_entries')->where('id', $id)->value('amount'));
    }

    public function test_the_database_itself_refuses_to_change_or_delete_an_audit_row(): void
    {
        $company = $this->company();
        $log = Audit::record('month.closed', null, ['month' => '2026-08'], $company->id);

        $this->assertNotNull($this->refusedByDatabase(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'x.y'])));
        $this->assertNotNull($this->refusedByDatabase(fn () => DB::table('audit_logs')->where('id', $log->id)->delete()));

        $this->assertSame('month.closed', DB::table('audit_logs')->where('id', $log->id)->value('action'));
    }

    // ── Q19 ───────────────────────────────────────────────────────

    public function test_the_same_movement_cannot_be_posted_twice_but_corrections_can_repeat(): void
    {
        $trip = $this->runningTrip();
        $actor = Actor::user($this->admin);

        Tenant::forCompany($this->co->id, function () use ($trip, $actor) {
            $ledger = app(WalletLedger::class);
            $post = fn (string $type, float $amount) => $ledger->post($this->co->id, $this->drv->id, $trip->id, 'custody', $type, $amount, $trip, $actor);

            $post('bonus', 10);

            // The identical original posting again (double click, two requests at once): refused.
            $this->assertNotNull($this->refusedByDatabase(fn () => $post('bonus', 10)));

            // A correction may be posted as often as needed.
            $post('bonus_correction', 5);
            $post('bonus_correction', -2);
        });

        $this->assertSame(1, WalletEntry::query()->withoutGlobalScopes()->where('type', 'bonus')->count());
        $this->assertSame(2, WalletEntry::query()->withoutGlobalScopes()->where('type', 'bonus_correction')->count());
    }

    public function test_the_key_is_empty_for_corrections_and_for_movements_with_no_source(): void
    {
        $this->assertNull(WalletEntry::sourceKey('TripExpense', 5, 'custody', 'expense_correction'));
        $this->assertNull(WalletEntry::sourceKey(null, null, 'custody', 'custody_issued'));
        $this->assertSame('TripExpense:5:custody:expense', WalletEntry::sourceKey('TripExpense', 5, 'custody', 'expense'));
    }

    public function test_every_row_the_app_writes_gets_its_key(): void
    {
        $this->runningTrip();

        $issued = WalletEntry::query()->withoutGlobalScopes()->where('type', 'custody_issued')->firstOrFail();
        $this->assertStringStartsWith('TripEvent:'.$issued->source_id.':custody:custody_issued', (string) $issued->source_key);
    }

    // ── Q18 ───────────────────────────────────────────────────────

    public function test_a_successful_office_sign_in_is_written_to_the_audit_log(): void
    {
        $user = $this->officeUser();

        $this->post('/login', ['login' => $user->email, 'password' => 'password']);

        $log = AuditLog::query()->where('action', 'auth.login')->firstOrFail();
        $this->assertSame('user', $log->actor_type);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->company_id, $log->company_id);
        $this->assertNotNull($log->ip);
    }

    public function test_a_failed_sign_in_is_logged_against_the_account_but_never_stores_the_password(): void
    {
        $user = $this->officeUser();

        $this->post('/login', ['login' => $user->email, 'password' => 'SuperSecret!1']);

        $log = AuditLog::query()->where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->company_id, $log->company_id);
        $this->assertStringNotContainsString('SuperSecret', json_encode($log->toArray()));
    }

    public function test_a_failed_try_on_an_unknown_login_is_logged_with_what_was_typed(): void
    {
        $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'whatever']);

        $log = AuditLog::query()->where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame('system', $log->actor_type);
        $this->assertNull($log->company_id);
        $this->assertSame('nobody@example.com', $log->changes['login']);
    }

    public function test_being_locked_out_after_too_many_tries_is_logged_too(): void
    {
        $user = $this->officeUser();

        foreach (range(1, 6) as $i) {
            $this->post('/login', ['login' => $user->email, 'password' => 'wrong']);
        }

        $this->assertSame(5, AuditLog::query()->where('action', 'auth.login_failed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login_locked')->count());
    }

    public function test_driver_sign_ins_are_logged_without_the_pin(): void
    {
        $driver = $this->driver();

        $this->postJson('/driver/api/login', ['mobile' => $driver->mobile, 'pin' => '9999'])->assertStatus(422);
        $this->postJson('/driver/api/login', ['mobile' => $driver->mobile, 'pin' => '1234'])->assertOk();

        $failed = AuditLog::query()->where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame('driver', $failed->actor_type);
        $this->assertSame($driver->id, $failed->actor_id);
        $this->assertStringNotContainsString('9999', json_encode($failed->toArray()));
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login')->where('actor_type', 'driver')->count());
    }
}
