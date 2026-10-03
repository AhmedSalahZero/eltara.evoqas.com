<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Routes, standard budgets and rate cards (Step 2)
//  Location: database/migrations/2026_10_01_000004_create_routes_budgets_and_rate_cards.php
//
//  trip_routes        — a route the company runs: origin → destination,
//                       km for the ROUND trip, usual hours (Scope §6.6)
//                       (called trip_routes because "routes" means web
//                       addresses inside Laravel)
//  trip_route_budgets — the route's standard cost per expense category
//                       (fuel 3,450 · tolls 260 …). Used to suggest the
//                       custody and to flag an expense more than 15%
//                       above its standard. The same for every customer.
//  rate_cards         — the price agreed with ONE customer for ONE route
//                       (round trip, Scope §6.9). It fills in the trip
//                       price automatically in Step 3.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('origin_ar', 80);
            $table->string('origin_en', 80)->nullable();
            $table->string('destination_ar', 80);
            $table->string('destination_en', 80)->nullable();
            $table->unsignedInteger('km_round_trip');
            $table->decimal('usual_hours', 5, 1)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('trip_route_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['trip_route_id', 'expense_category_id']);
        });

        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_route_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->string('notes', 250)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'trip_route_id']);
            $table->index(['company_id', 'trip_route_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_cards');
        Schema::dropIfExists('trip_route_budgets');
        Schema::dropIfExists('trip_routes');
    }
};
