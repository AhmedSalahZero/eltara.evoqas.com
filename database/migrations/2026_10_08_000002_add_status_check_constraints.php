<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Audit fix Q20: the database itself refuses a made-up status
//  Location: database/migrations/2026_10_08_000002_add_status_check_constraints.php
//
//  Statuses were free text. A typo or a bug could save "setled" and no
//  screen would know what it means. Each status-like column now has a
//  CHECK that lists the values the application really uses (the same
//  lists as in the models). Anything else is refused by the database.
//
//  MySQL 8.0.16+ and MariaDB 10.2+ enforce CHECK. SQLite (used by the
//  automated tests) cannot add a CHECK to an existing table, so it is
//  skipped there — the application's own rules still apply.
//  If a table already holds a value outside its list, that one
//  constraint is not added and the reason is written to the log
//  (storage/logs): fix the rows, then run the migration again.
//
//  If you ADD a new status in the code, add it to the list here in a
//  new migration (drop the constraint and add it again).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    /** table => [column => allowed values] */
    private const RULES = [
        'companies'          => ['status' => ['active', 'trial', 'suspended']],
        'users'              => ['role' => ['super_admin', 'company_admin', 'office_user']],
        'vehicles'           => ['status' => ['available', 'maintenance']],
        'trips'              => ['status' => ['planned', 'accepted', 'loading', 'on_road', 'delivered', 'settled', 'cancelled'], 'transfer_policy' => ['approval', 'limit', 'auto']],
        'trip_charges'       => ['kind' => ['extra', 'deduction']],
        'trip_expenses'      => ['paid_from' => ['custody', 'collections', 'own_pocket', 'company']],
        'wallet_transfers'   => ['status' => ['pending', 'approved', 'auto', 'rejected', 'cancelled']],
        'wallet_entries'     => ['wallet' => ['custody', 'collections', 'advances', 'pocket']],
        'driver_advances'    => ['status' => ['open', 'repaid', 'cancelled']],
        'client_requests'    => ['status' => ['new', 'approved', 'assigned', 'declined', 'cancelled']],
        'client_complaints'  => ['status' => ['open', 'answered']],
        'month_closes'       => ['status' => ['closed', 'open']],
        'sync_receipts'      => ['status' => ['applied', 'rejected']],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::RULES as $table => $columns) {
            foreach ($columns as $column => $values) {
                $list = implode(', ', array_map(fn ($v) => "'{$v}'", $values));

                try {
                    DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `chk_{$table}_{$column}` CHECK (`{$column}` IN ({$list}))");
                } catch (\Throwable $e) {
                    Log::warning("Status check for {$table}.{$column} was NOT added: ".$e->getMessage());
                }
            }
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::RULES as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `chk_{$table}_{$column}`");
                } catch (\Throwable) {
                    // It was never added.
                }
            }
        }
    }
};
