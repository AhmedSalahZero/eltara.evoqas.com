<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Cash from the client: confirmed by the office on his behalf
//  Location: database/migrations/2026_10_15_000001_add_office_confirm_to_trip_collections.php
//
//  A client who has no portal link (or never opens it) cannot confirm the
//  cash he handed to the driver. Someone with authority in the company
//  may confirm for him. These two columns say who did it and why, so a
//  confirmation made by the office is never mistaken for the client's own.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_collections', function (Blueprint $table) {
            $table->foreignId('client_confirmed_for_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_confirm_note', 250)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trip_collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_confirmed_for_by');
            $table->dropColumn('client_confirm_note');
        });
    }
};
