<?php

use App\Models\WalletEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Audit fix Q19: the ledger cannot post the same thing twice
//  Location: database/migrations/2026_10_08_000001_add_source_key_to_wallet_entries.php
//
//  Until now "this expense / collection / transfer was already posted"
//  was guaranteed only by application code. Now the database enforces
//  it: every original ledger row carries a key
//  "<record type>:<record id>:<wallet>:<type>" and the key is UNIQUE.
//  A second identical post (a double click, two requests at the same
//  moment) is refused by the database. Corrections (…_correction) and
//  movements without a source have no key, because they may repeat.
//
//  Rows already in the ledger get their key here. This runs BEFORE
//  the protection migration (2026_10_08_000003) that forbids editing
//  ledger rows. If old data already holds a true duplicate, only the
//  first row gets the key; nothing is deleted or changed otherwise.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->string('source_key', 150)->nullable()->after('source_id');
        });

        $seen = [];
        DB::table('wallet_entries')->orderBy('id')->select(['id', 'source_type', 'source_id', 'wallet', 'type'])->chunk(500, function ($rows) use (&$seen) {
            foreach ($rows as $row) {
                $key = WalletEntry::sourceKey($row->source_type, $row->source_id, $row->wallet, $row->type);

                if ($key === null || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                DB::table('wallet_entries')->where('id', $row->id)->update(['source_key' => $key]);
            }
        });

        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->unique('source_key', 'wallet_entries_source_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->dropUnique('wallet_entries_source_key_unique');
            $table->dropColumn('source_key');
        });
    }
};
