<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  El Tara — audit follow-up: who physically received the cash
//  Location: database/migrations/2026_10_07_000001_add_cash_received_by_to_trip_settlements.php
//
//  A settlement where the driver hands money in now records the name
//  of the person who took it (default: the person settling).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_settlements', function (Blueprint $table) {
            $table->string('cash_received_by', 120)->nullable()->after('unconfirmed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('trip_settlements', function (Blueprint $table) {
            $table->dropColumn('cash_received_by');
        });
    }
};
