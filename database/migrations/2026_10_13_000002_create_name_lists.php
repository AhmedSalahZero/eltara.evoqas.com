<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Name lists for insurance companies and route places
//  Location: database/migrations/2026_10_13_000002_create_name_lists.php
//
//  The truck form and the route form pick a name from a list, or add
//  one. The truck and the route still store the name as text, so
//  nothing that already reads those columns changes. Names already
//  typed on trucks and routes are copied in here.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('route_place_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('language', 2);          // ar | en
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['company_id', 'language', 'name']);
        });

        $now = now();
        $seen = [];
        foreach (DB::table('vehicles')->whereNotNull('insurance_company')->get(['id', 'company_id', 'insurance_company']) as $row) {
            $name = mb_substr(trim((string) $row->insurance_company), 0, 80);
            if ($name === '') {
                continue;
            }
            if ($name !== $row->insurance_company) {
                DB::table('vehicles')->where('id', $row->id)->update(['insurance_company' => $name]);
            }
            $key = $row->company_id.'|'.$name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            DB::table('insurance_companies')->insert([
                'company_id' => $row->company_id, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $places = [];
        foreach (['origin_ar' => 'ar', 'destination_ar' => 'ar', 'origin_en' => 'en', 'destination_en' => 'en'] as $column => $language) {
            foreach (DB::table('trip_routes')->whereNotNull($column)->get(['id', 'company_id', $column]) as $row) {
                $name = mb_substr(trim((string) $row->{$column}), 0, 80);
                if ($name === '') {
                    continue;
                }
                if ($name !== $row->{$column}) {
                    DB::table('trip_routes')->where('id', $row->id)->update([$column => $name]);
                }
                $key = $row->company_id.'|'.$language.'|'.$name;
                if (isset($places[$key])) {
                    continue;
                }
                $places[$key] = true;
                DB::table('route_place_names')->insert([
                    'company_id' => $row->company_id, 'language' => $language, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('route_place_names');
        Schema::dropIfExists('insurance_companies');
    }
};
