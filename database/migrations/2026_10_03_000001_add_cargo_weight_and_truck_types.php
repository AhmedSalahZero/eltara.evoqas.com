<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Cargo (goods) + Weight on trips, and Truck types
//  Location: database/migrations/2026_10_03_000001_add_cargo_weight_and_truck_types.php
//
//  1. trips.cargo was a free-text box meant as "weight". It is now two
//     things: the GOODS (cargo_type_id → cargo_types, a list) and the
//     WEIGHT in tons (trips.weight_tons). Old text is kept: every
//     different text becomes a goods type and old trips point to it.
//  2. vehicles.type was 5 fixed words. Now vehicles.vehicle_type_id →
//     vehicle_types (12 standard types per company + the company's own).
//     Old vehicles are mapped: trailer → Tractor-Trailer, jumbo → Extra
//     Heavy Truck, heavy → Heavy Truck, tanker → Tanker Truck,
//     other → no type (the office picks one).
//  The old columns (vehicles.type, trips.cargo) are left in place,
//  unused, so nothing is lost. Nothing existing is deleted.
// ══════════════════════════════════════════════════════════════════

use App\Models\VehicleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->nullable();
            $table->string('name_ar', 80);
            $table->string('name_en', 80)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(500);
            $table->timestamps();

            $table->unique(['company_id', 'name_ar']);
            $table->index(['company_id', 'code']);
        });

        Schema::create('cargo_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar', 80);
            $table->string('name_en', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(500);
            $table->timestamps();

            $table->unique(['company_id', 'name_ar']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('vehicle_type_id')->nullable()->after('type')->constrained('vehicle_types')->restrictOnDelete();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('cargo_type_id')->nullable()->after('cargo')->constrained('cargo_types')->restrictOnDelete();
            $table->decimal('weight_tons', 8, 2)->nullable()->after('cargo_type_id');
        });

        $this->carryOverExistingData();
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cargo_type_id');
            $table->dropColumn('weight_tons');
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_type_id');
        });
        Schema::dropIfExists('cargo_types');
        Schema::dropIfExists('vehicle_types');
    }

    private function carryOverExistingData(): void
    {
        $now = now();
        $oldToNew = ['trailer' => 'tractor_trailer', 'jumbo' => 'extra_heavy', 'heavy' => 'heavy', 'tanker' => 'tanker'];

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            // The 12 standard truck types for this company
            $sort = 10;
            $ids = [];
            foreach (VehicleType::STANDARD as $code => [$ar, $en]) {
                $ids[$code] = DB::table('vehicle_types')->insertGetId([
                    'company_id' => $companyId, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en,
                    'is_system' => true, 'is_active' => true, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $sort += 10;
            }

            foreach ($oldToNew as $old => $code) {
                DB::table('vehicles')->where('company_id', $companyId)->where('type', $old)->update(['vehicle_type_id' => $ids[$code]]);
            }

            // Old free-text cargo → goods types
            $texts = DB::table('trips')->where('company_id', $companyId)->whereNotNull('cargo')->where('cargo', '!=', '')->distinct()->pluck('cargo');
            foreach ($texts as $text) {
                $text = trim((string) $text);
                if ($text === '') {
                    continue;
                }
                $id = DB::table('cargo_types')->where('company_id', $companyId)->where('name_ar', $text)->value('id')
                    ?? DB::table('cargo_types')->insertGetId(['company_id' => $companyId, 'name_ar' => $text, 'name_en' => null, 'is_active' => true, 'sort' => 500, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('trips')->where('company_id', $companyId)->where('cargo', $text)->update(['cargo_type_id' => $id]);
            }
        }
    }
};
