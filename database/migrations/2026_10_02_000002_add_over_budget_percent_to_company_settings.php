<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Over-budget flag, set per company (Step 3)
//  Location: database/migrations/2026_10_02_000002_add_over_budget_percent_to_company_settings.php
//
//  company_settings.over_budget_percent — an expense category on a
//  trip is flagged "over budget" when it is more than this % above
//  the route's standard (Scope §6.6 says 15%, so 15 is the default).
//  Each company can change it in Company settings → Wallets.
//  Adds one column; nothing existing is changed or lost.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('over_budget_percent', 5, 2)->default(15)->after('custody_buffer_percent');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('over_budget_percent');
        });
    }
};
