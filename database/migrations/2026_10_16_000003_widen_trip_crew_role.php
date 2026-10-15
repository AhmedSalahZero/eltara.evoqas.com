<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Trip crew: the role column was too short
//  Location: database/migrations/2026_10_16_000003_widen_trip_crew_role.php
//
//  "second_driver" has 13 letters and the column held 12, so MySQL refused it
//  (the SQLite test database does not check the length). The create migration
//  now says 20; this one fixes databases that were already migrated.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_crew', function (Blueprint $table) {
            $table->string('role', 20)->change();
        });
    }

    public function down(): void
    {
        // Left as it is: shortening the column again would cut "second_driver".
    }
};
