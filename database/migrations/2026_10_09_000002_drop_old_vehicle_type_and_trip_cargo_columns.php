<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  El Tara — remove two old columns nothing uses any more
//  Location: database/migrations/2026_10_09_000002_drop_old_vehicle_type_and_trip_cargo_columns.php
//
//  vehicles.type  the old fixed list of truck types      → vehicles.vehicle_type_id
//  trips.cargo    the old free-text goods name           → trips.cargo_type_id
//
//  The migration of step "cargo + weight + truck types"
//  (2026_10_03_000001) already copied every old value into the new
//  lists, and no screen, report or app code reads or writes the old
//  columns (they are not even in the models' fillable lists).
//  A column that is not used is a trap: someone fills it later and
//  it silently disagrees with the real one.
//
//  down() puts them back (empty) so the migration can be rolled back.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vehicles', 'type')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('type'));
        }

        if (Schema::hasColumn('trips', 'cargo')) {
            Schema::table('trips', fn (Blueprint $table) => $table->dropColumn('cargo'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vehicles', 'type')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->string('type', 12)->default('trailer'));
        }

        if (! Schema::hasColumn('trips', 'cargo')) {
            Schema::table('trips', fn (Blueprint $table) => $table->string('cargo', 200)->nullable());
        }
    }
};
