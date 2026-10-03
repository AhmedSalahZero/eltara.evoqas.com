<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Route weight (الحمولة) in tons
//  Location: database/migrations/2026_10_03_000002_add_weight_to_trip_routes.php
//
//  The weight a truck carries on the route is a main factor in many
//  expenses, so it belongs to the route: "6 October – Alexandria –
//  5 Ton" and "6 October – Alexandria – 20 Ton" are two routes, each
//  with its own standard budget and customer prices.
//  Existing routes keep an empty weight (nothing is changed or lost);
//  the office fills it in the next time it edits each route.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_routes', function (Blueprint $table) {
            $table->decimal('weight_tons', 8, 2)->nullable()->after('destination_en');
        });
    }

    public function down(): void
    {
        Schema::table('trip_routes', function (Blueprint $table) {
            $table->dropColumn('weight_tons');
        });
    }
};
