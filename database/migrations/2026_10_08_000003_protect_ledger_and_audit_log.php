<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Audit fix Q17: the ledger and the audit log cannot be edited
//  Location: database/migrations/2026_10_08_000003_protect_ledger_and_audit_log.php
//
//  Until now "these rows are permanent" was only a rule inside the app.
//  Anyone with access to the database could change or delete a ledger
//  line or an audit line without a trace. Now the DATABASE refuses:
//  every UPDATE and DELETE on wallet_entries and audit_logs is blocked
//  by a trigger. The app only ever adds rows, so nothing changes for it.
//  A correction is still a new row.
//
//  Honest limit: a person with full database-administrator rights can
//  still drop a trigger. This stops mistakes, scripts and ordinary
//  database users; it does not stop a determined administrator. For
//  that, also keep the database admin account to very few people and
//  keep off-site backups.
//
//  Needs MySQL/MariaDB (production) or SQLite (tests). On hosting where
//  the database user may not create triggers, the migration does not
//  fail: it writes a warning to the log (storage/logs) instead.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    private const TABLES = ['wallet_entries', 'audit_logs'];

    private const MESSAGE = 'This table is permanent: rows cannot be changed or deleted.';

    public function up(): void
    {
        $driver = DB::getDriverName();

        foreach (self::TABLES as $table) {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
                $trigger = "{$table}_no_{$name}";

                try {
                    match ($driver) {
                        'mysql', 'mariadb' => DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'"),
                        'sqlite'           => DB::unprepared("CREATE TRIGGER \"{$trigger}\" BEFORE {$event} ON \"{$table}\" BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END"),
                        default            => Log::warning("Ledger protection trigger {$trigger} was NOT added: database type '{$driver}' is not supported."),
                    };
                } catch (\Throwable $e) {
                    Log::warning("Ledger protection trigger {$trigger} was NOT added: ".$e->getMessage());
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $name) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.(DB::getDriverName() === 'sqlite' ? "\"{$table}_no_{$name}\"" : "`{$table}_no_{$name}`"));
            }
        }
    }
};
