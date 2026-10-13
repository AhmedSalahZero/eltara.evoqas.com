<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Workshop store and maintenance cost on trips
//  Location: database/migrations/2026_10_13_000003_create_workshop_stock.php
//
//  A company workshop holds stock (oil, filters, tyres). A receipt
//  brings goods in at a price. Issuing them to a truck is the cost
//  of that maintenance job. An outside workshop is just a bill.
//  Either cost is then shared onto the truck's later trips as a
//  company-paid expense. Jobs from before this migration do not
//  share (shares_trips stays false).
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('has_workshop')->default(false);
        });

        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->string('source', 10)->default('external'); // external | own
            $table->foreignId('store_id')->nullable();
            $table->boolean('shares_trips')->default(false);
        });

        Schema::table('trip_expenses', function (Blueprint $table) {
            $table->foreignId('maintenance_job_id')->nullable()->constrained('maintenance_jobs')->nullOnDelete();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('unit', 12); // litre | piece | kg
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('value', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'stock_item_id']);
        });

        Schema::create('stock_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->string('direction', 3); // in | out
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('amount', 14, 2);
            $table->string('supplier', 120)->nullable();
            $table->foreignId('maintenance_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->string('note', 250)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'occurred_at']);
        });

        Schema::create('maintenance_job_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->foreignId('stock_move_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_job_lines');
        Schema::dropIfExists('stock_moves');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stores');

        Schema::table('trip_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_job_id');
        });
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropColumn(['source', 'store_id', 'shares_trips']);
        });
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('has_workshop');
        });
    }
};
