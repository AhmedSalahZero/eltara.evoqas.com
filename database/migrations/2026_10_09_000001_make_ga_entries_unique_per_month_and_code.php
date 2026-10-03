<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  El Tara — one standard G&A line per company, month and code
//  Location: database/migrations/2026_10_09_000001_make_ga_entries_unique_per_month_and_code.php
//
//  Before this, "Rent" could be entered twice for the same month and
//  both rows were counted. Now the database refuses a second one
//  (lines with their own name — code empty — can still repeat).
//
//  Duplicates that ALREADY exist are combined first, so the total
//  G&A of the month does not change: the amounts are added into the
//  oldest row and the others are removed.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('ga_entries')->whereNotNull('code')
            ->select('company_id', 'month', 'code', DB::raw('COUNT(*) as n'))
            ->groupBy('company_id', 'month', 'code')->havingRaw('COUNT(*) > 1')->get();

        foreach ($groups as $g) {
            $rows = DB::table('ga_entries')->where('company_id', $g->company_id)->where('month', $g->month)->where('code', $g->code)->orderBy('id')->get();
            $keep = $rows->first();

            DB::table('ga_entries')->where('id', $keep->id)->update(['amount' => round((float) $rows->sum('amount'), 2), 'updated_at' => now()]);
            DB::table('ga_entries')->whereIn('id', $rows->skip(1)->pluck('id')->all())->delete();
        }

        Schema::table('ga_entries', function (Blueprint $table) {
            $table->unique(['company_id', 'month', 'code'], 'ga_entries_company_month_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ga_entries', function (Blueprint $table) {
            $table->dropUnique('ga_entries_company_month_code_unique');
        });
    }
};
