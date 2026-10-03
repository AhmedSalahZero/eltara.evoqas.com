<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Step 6: fuel, advances, invoice numbers, month close
//  Location: database/migrations/2026_10_06_000001_create_fuel_advances_invoices_and_month_close.php
//
//  Adds (nothing existing is changed or deleted):
//
//  fuel_entries              each refuel: litres, price, amount, odometer,
//                            station, paid by company card or custody cash.
//                            May point at the trip and at the trip expense
//                            it belongs to, so the cost is counted once.
//  driver_advance_repayments each amount taken back from an advance: a
//                            payroll deduction (one per advance per
//                            month — enforced by a unique key), or cash.
//  invoices                  an invoice number from the company's ERP.
//  trips.invoice_id          which invoice a trip is linked to. One
//                            invoice covers several trips of one customer.
//  ga_entries                the G&A lines of a month (salaries, rent …).
//  month_closes              one row per closed month: the allocation rate
//                            and the month's figures, frozen at close.
//  trip_allocations          the share of a trip's km (and so of G&A) that
//                            belongs to one closed month. A trip that spans
//                            two months has two rows.
//  company_settings.fuel_flag_percent
//                            a truck is flagged when its km/L is more than
//                            this % below its standard (7% in the scope).
//
//  Money columns are decimal: exact to the piastre.
//  Months are stored as the first day of the month (2026-09-01).
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('fuel_flag_percent', 5, 2)->default(7)->after('over_budget_percent');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('number', 60);                    // as printed by the ERP
            $table->date('issued_on')->nullable();
            $table->string('note', 250)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });

        Schema::create('fuel_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('trip_expense_id')->nullable()->index();
            $table->dateTime('filled_at');
            $table->decimal('litres', 9, 2);
            $table->decimal('price_per_litre', 8, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->boolean('litres_estimated')->default(false);   // litres = amount ÷ diesel price
            $table->unsignedInteger('odometer_km')->nullable();
            $table->string('station', 120)->nullable();
            $table->string('paid_by', 8);                    // card | custody
            $table->string('note', 250)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'filled_at']);
            $table->index(['vehicle_id', 'filled_at']);
        });

        Schema::create('driver_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_advance_id')->constrained('driver_advances')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 8);                     // payroll | cash
            $table->date('deduction_month')->nullable();     // payroll: the month's first day
            $table->string('note', 250)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One payroll deduction per advance per month, even if two people press the button.
            $table->unique(['driver_advance_id', 'deduction_month'], 'advance_month_unique');
            $table->index(['company_id', 'deduction_month']);
        });

        Schema::create('ga_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('month');                           // first day of the month
            $table->string('code', 20)->nullable();         // a standard line (salaries, rent …) or null = own name
            $table->string('label', 120);
            $table->decimal('amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'month']);
        });

        Schema::create('month_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->string('status', 8)->default('closed');  // closed | open (re-opened)
            $table->decimal('ga_total', 14, 2)->default(0);
            $table->decimal('km', 12, 2)->default(0);        // the km the G&A was divided by
            $table->decimal('rate', 12, 6)->default(0);      // EGP per km
            $table->unsignedInteger('trips_count')->default(0);
            $table->decimal('revenue', 14, 2)->default(0);
            $table->decimal('direct_profit', 14, 2)->default(0);
            $table->decimal('true_profit', 14, 2)->default(0);
            $table->string('split_rule', 10)->nullable();    // the rule used, kept for the record
            $table->string('basis', 10)->nullable();         // own_km | all_km
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->string('reopen_reason', 250)->nullable();
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'month']);
        });

        Schema::create('trip_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->decimal('km', 10, 2);
            $table->decimal('hours', 8, 2)->default(0);
            $table->decimal('rate', 12, 6);
            $table->decimal('ga_share', 12, 2);
            $table->boolean('is_estimate')->default(false);  // the trip was still on the road at close
            $table->timestamps();

            $table->unique(['trip_id', 'month']);
            $table->index(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_allocations');
        Schema::dropIfExists('month_closes');
        Schema::dropIfExists('ga_entries');
        Schema::dropIfExists('driver_advance_repayments');
        Schema::dropIfExists('fuel_entries');
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
        Schema::dropIfExists('invoices');
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('fuel_flag_percent');
        });
    }
};
