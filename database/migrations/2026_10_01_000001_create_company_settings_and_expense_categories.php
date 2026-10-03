<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Company settings + expense categories (Step 2)
//  Location: database/migrations/2026_10_01_000001_create_company_settings_and_expense_categories.php
//
//  company_settings — ONE row per company with its business rules
//  (Scope §6.15). Created automatically the first time a company is
//  used (App\Services\CompanyDefaults), with the defaults below.
//    wallets:     default transfer policy, automatic-transfer limit,
//                 custody buffer %, personal spend → advance
//    true profit: two-month rule, G&A basis, G&A per km estimate
//    driver app:  receipt photo, capture location, offline mode,
//                 hours without sync before an alert
//    general:     diesel price per litre, currency
//  (Default language and theme stay on the companies table.)
//
//  expense_categories — the lines a trip's costs are recorded under
//  (Scope §6.6): the 10 standard ones are created for every company
//  (is_system = true, cannot be deleted), and each company can add
//  its own. cash_percent = how much of that category the driver
//  normally pays in cash from custody (fuel is partly paid by
//  company card; the hired-truck fee is paid by the office) — used
//  to suggest the custody amount for a route.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // Wallets & approvals
            $table->string('default_transfer_policy', 10)->default('limit'); // approval | limit | auto
            $table->decimal('auto_transfer_limit', 12, 2)->default(1000);
            $table->decimal('custody_buffer_percent', 5, 2)->default(5);
            $table->boolean('personal_spend_to_advance')->default(true);

            // True profit & month close
            $table->string('month_split_rule', 10)->default('hours');       // hours | start | delivery
            $table->string('ga_basis', 10)->default('own_km');              // own_km | all_km
            $table->decimal('ga_rate_estimate', 8, 2)->nullable();          // EGP per km until a month is closed

            // Driver app
            $table->boolean('receipt_photo_required')->default(true);
            $table->boolean('capture_location')->default(true);
            $table->boolean('offline_mode')->default(true);
            $table->unsignedSmallInteger('max_hours_without_sync')->default(12);

            // General
            $table->decimal('diesel_price', 8, 2)->default(20.50);
            $table->char('currency', 3)->default('EGP');

            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->nullable();                 // fuel, toll … for the standard ones
            $table->string('name_ar', 80);
            $table->string('name_en', 80)->nullable();
            $table->string('icon', 20)->default('receipt');
            $table->unsignedTinyInteger('cash_percent')->default(100);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('company_settings');
    }
};
