<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Step 5 follow-up migration: several kinds of truck in one request
//  Location: database/migrations/2026_10_05_000003_create_client_request_lines.php
//
//  A client often asks for "4 trucks of 5 Ton and 2 trucks of 1 Ton" to
//  the same place. A request now has LINES: each line is one route (the
//  route carries its weight — "6 October – Alexandria – 5 Ton") with a
//  number of trucks and the client's agreed price per truck, copied at
//  the moment of the request. client_requests.trucks_count stays as the
//  total of all lines. Requests made before this change get one line
//  each, so nothing is lost.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_route_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('trucks_count');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->index('client_request_id');
        });

        DB::table('client_requests')->orderBy('id')->each(function ($r) {
            DB::table('client_request_lines')->insert([
                'company_id' => $r->company_id, 'client_request_id' => $r->id, 'trip_route_id' => $r->trip_route_id,
                'trucks_count' => $r->trucks_count, 'unit_price' => $r->expected_unit_price ?? 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_request_lines');
    }
};
