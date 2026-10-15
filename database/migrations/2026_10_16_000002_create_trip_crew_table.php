<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Trip crew (second driver, assistant)
//  Location: database/migrations/2026_10_16_000002_create_trip_crew_table.php
//
//  The trip has ONE main driver, who holds all the money. A second driver
//  (long trips) or an assistant is only recorded here, with the allowance
//  he gets. That allowance is paid by the main driver, so it becomes an
//  ordinary trip expense (category "allowance") once the trip is loading;
//  trip_expense_id is that expense.
//  budget_added = what was added to the trip's standard allowance budget
//  for this person, so the budget check does not flag his allowance.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);                 // second_driver | assistant
            $table->string('name', 80);
            $table->decimal('allowance', 12, 2)->default(0);
            $table->decimal('budget_added', 12, 2)->default(0);
            $table->string('note', 250)->nullable();
            $table->foreignId('trip_expense_id')->nullable()->constrained('trip_expenses')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_crew');
    }
};
