<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Vehicles (Step 2, Scope §6.7)
//  Location: database/migrations/2026_10_01_000002_create_vehicles_table.php
//
//  One row per truck, own fleet or hired:
//    plate        — Egyptian plate: numbers + Arabic letters,
//                   unique inside a company
//    type         — trailer | jumbo | heavy | tanker | other
//    ownership    — own | hired (a hired truck has an owner, gets no
//                   custody and carries no share of G&A — Scope §6.7)
//    driver_id    — the vehicle's usual driver (suggested on trips)
//    std_km_per_litre — the standard fuel economy it is judged against
//    status       — available | maintenance ("on a trip" is worked
//                   out from the trips themselves, Step 3)
//    documents    — licence, insurance and technical inspection, each
//                   with its expiry date: an alert shows 30 days before.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('plate_number', 10);
            $table->string('plate_letters', 12);
            $table->string('type', 12)->default('trailer');
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->decimal('capacity_tons', 6, 1)->nullable();

            $table->string('ownership', 6)->default('own');
            $table->string('owner_name', 120)->nullable();
            $table->string('owner_phone', 20)->nullable();

            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->decimal('std_km_per_litre', 5, 2)->nullable();
            $table->string('status', 12)->default('available');

            $table->string('licence_number', 40)->nullable();
            $table->date('licence_expires_at')->nullable();
            $table->string('insurance_company', 80)->nullable();
            $table->string('insurance_policy_number', 40)->nullable();
            $table->date('insurance_expires_at')->nullable();
            $table->date('inspection_expires_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'plate_number', 'plate_letters']);
            $table->unique('driver_id');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'ownership']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
