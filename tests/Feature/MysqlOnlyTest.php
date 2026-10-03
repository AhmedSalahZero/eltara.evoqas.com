<?php

namespace Tests\Feature;

use App\Models\WalletEntry;
use App\Services\Trips\Actor;
use App\Services\Trips\SettlementService;
use App\Services\Trips\TripService;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the protections that only a real MySQL can prove
//  Location: tests/Feature/MysqlOnlyTest.php
//  Audit report item Q22 (and the MySQL half of Q17, Q19, Q20).
//
//  The normal test run uses SQLite in memory, where "lock this row"
//  (lockForUpdate) does nothing and CHECK constraints / triggers
//  differ. These tests therefore RUN ONLY ON MYSQL / MARIADB and are
//  skipped (not failed) on SQLite. Run them with:
//
//      php artisan test --configuration=phpunit.mysql.xml
//
//  They need an EMPTY test database (see phpunit.mysql.xml) because
//  the tables are rebuilt for every test.
//
//  How the lock is proven: a second database connection grabs the
//  trip's row lock and keeps it. The app then tries to settle /
//  create on that same row. If the app really locks the row it must
//  WAIT, and (with a 1-second wait limit) fail with "Lock wait
//  timeout". If it did not lock, it would sail through — and two
//  people could settle the same trip together.
// ══════════════════════════════════════════════════════════════════

class MysqlOnlyTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, DatabaseMigrations;

    private const LOCK_WAIT_TIMEOUT = 1205;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MySQL/MariaDB: SQLite cannot prove row locking, triggers or CHECK constraints.');
        }

        Storage::fake('trip_files');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge('second');

        parent::tearDown();
    }

    /** A second, independent connection to the same database (so it can hold a lock the app must respect). */
    private function secondConnection(): \Illuminate\Database\Connection
    {
        config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

        return DB::connection('second');
    }

    private function databaseError(callable $change): ?QueryException
    {
        try {
            $change();
        } catch (QueryException $e) {
            return $e;
        }

        return null;
    }

    private function deliveredTrip(): \App\Models\Trip
    {
        $trip = $this->runningTrip();

        return Tenant::forCompany($this->co->id, function () use ($trip) {
            app(TripService::class)->deliver($trip, $this->pod(), 'Store keeper', Actor::user($this->admin));

            return $trip->fresh();
        });
    }

    public function test_settling_a_trip_waits_for_the_trips_row_lock(): void
    {
        $trip = $this->deliveredTrip();
        $other = $this->secondConnection();
        $other->beginTransaction();

        try {
            // Someone else is working on this trip right now and holds its row.
            $other->table('trips')->where('id', $trip->id)->lockForUpdate()->first();

            $error = $this->databaseError(fn () => Tenant::forCompany($this->co->id, fn () => app(SettlementService::class)->settle($trip, $this->admin)));

            $this->assertNotNull($error, 'Settlement did not wait for the trip row lock — two people could settle together.');
            $this->assertSame(self::LOCK_WAIT_TIMEOUT, $error->errorInfo[1] ?? null);
            $this->assertSame('delivered', $trip->fresh()->status);
        } finally {
            $other->rollBack();
        }

        // Lock released: the same settlement now goes through.
        Tenant::forCompany($this->co->id, fn () => app(SettlementService::class)->settle($trip->fresh(), $this->admin));
        $this->assertSame('settled', $trip->fresh()->status);
    }

    public function test_new_trip_numbers_are_taken_under_a_lock_so_two_trips_never_share_one(): void
    {
        $this->runningTrip();
        $other = $this->secondConnection();
        $other->beginTransaction();

        try {
            $other->table('trips')->where('company_id', $this->co->id)->lockForUpdate()->get();

            $error = $this->databaseError(fn () => Tenant::forCompany($this->co->id, fn () => app(TripService::class)->create($this->tripData(['vehicle_id' => $this->truck->id]), $this->admin)));

            $this->assertNotNull($error, 'Trip numbering did not wait for the lock — two trips could get the same number.');
            $this->assertSame(self::LOCK_WAIT_TIMEOUT, $error->errorInfo[1] ?? null);
        } finally {
            $other->rollBack();
        }
    }

    public function test_mysql_refuses_to_change_or_delete_ledger_and_audit_rows(): void
    {
        $this->runningTrip();
        $id = (int) WalletEntry::query()->withoutGlobalScopes()->value('id');
        \App\Support\Audit::record('month.closed', null, [], $this->co->id);

        $this->assertNotNull($this->databaseError(fn () => DB::table('wallet_entries')->where('id', $id)->update(['amount' => 1])));
        $this->assertNotNull($this->databaseError(fn () => DB::table('wallet_entries')->where('id', $id)->delete()));
        $this->assertNotNull($this->databaseError(fn () => DB::table('audit_logs')->update(['action' => 'x.y'])));
        $this->assertNotNull($this->databaseError(fn () => DB::table('audit_logs')->delete()));
    }

    public function test_mysql_refuses_the_same_ledger_posting_twice(): void
    {
        $this->runningTrip();
        $row = (array) DB::table('wallet_entries')->whereNotNull('source_key')->first();
        $this->assertNotEmpty($row, 'No ledger row has a source key.');

        unset($row['id']);

        $this->assertNotNull($this->databaseError(fn () => DB::table('wallet_entries')->insert($row)));
    }

    public function test_mysql_refuses_a_made_up_status(): void
    {
        $trip = $this->runningTrip();

        $this->assertNotNull($this->databaseError(fn () => DB::table('trips')->where('id', $trip->id)->update(['status' => 'setled'])), 'The trips.status CHECK is missing.');
        $this->assertNotNull($this->databaseError(fn () => DB::table('trips')->where('id', $trip->id)->update(['transfer_policy' => 'maybe'])));
        $this->assertNotNull($this->databaseError(fn () => DB::table('vehicles')->update(['status' => 'broken'])));

        // A real status still works.
        DB::table('trips')->where('id', $trip->id)->update(['status' => 'delivered']);
        $this->assertSame('delivered', $trip->fresh()->status);
    }
}
