<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Route time in two parts: driving and waiting
//  Location: database/migrations/2026_10_16_000001_add_wait_and_road_hours.php
//
//  trip_routes.usual_hours  = DRIVING hours, round trip (there and back)
//  trip_routes.wait_hours   = WAITING hours: loading + unloading + paperwork
//  trips.road_hours         = the driving hours copied from the route
//                             (the client's "expected arrival" uses half)
//  trips.planned_hours      = driving + waiting = how long the truck is busy
//                             (fleet utilisation, km split by hours, maintenance)
//  Old trips keep what they had: their road_hours is filled from planned_hours,
//  so nothing already on screen changes.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_routes', function (Blueprint $table) {
            $table->decimal('wait_hours', 5, 1)->nullable();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('road_hours', 5, 1)->nullable();
        });

        DB::table('trips')->whereNotNull('planned_hours')->update(['road_hours' => DB::raw('planned_hours')]);
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('road_hours');
        });
        Schema::table('trip_routes', function (Blueprint $table) {
            $table->dropColumn('wait_hours');
        });
    }
};
