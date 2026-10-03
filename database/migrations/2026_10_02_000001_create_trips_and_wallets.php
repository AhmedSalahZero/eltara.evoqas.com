<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Trips, expenses, the three wallets, transfers and
//  settlement (Step 3, Scope §6.3 – §6.5, §10)
//  Location: database/migrations/2026_10_02_000001_create_trips_and_wallets.php
//
//  trips            — one trip = one truck, one route, one customer.
//                     Its own revenue and expenses (a mini P&L). The
//                     price and the standard budget are COPIED in when
//                     the trip is created, so later rate-card or budget
//                     changes never rewrite an old trip.
//  trip_charges     — extra charges (waiting / detention …) and
//                     deductions (late delivery …) on the revenue side.
//  trip_expenses    — every cost line: category, amount, who paid it
//                     (custody / collection money / driver's own pocket
//                     / the company directly), receipt photo.
//                     Personal spending is also recorded here, but it is
//                     NOT a trip cost — it becomes a driver advance.
//  trip_collections — cash a client handed to the driver for the
//                     company, with the two-sided confirmation
//                     (driver ✓ / client ✓ / disputed — Scope §9).
//  wallet_transfers — money moved between a driver's wallets
//                     (collections → custody), with its approval.
//  wallet_entries   — THE LEDGER. Every movement of money in a driver's
//                     wallets is one row here (custody issued, expense,
//                     transfer, collection, settlement …). A wallet
//                     balance is simply the sum of its rows. Rows are
//                     never changed or deleted: a correction is a new
//                     row, so the history always adds up.
//  driver_advances  — personal advances (سلف), deducted from salary.
//                     Step 3 creates them from personal spending on a
//                     trip; the advances screen and instalments are
//                     Step 6.
//  trip_settlements — the closing record of a trip's wallets.
//  trip_events      — the key moments (accepted, custody, loading,
//                     departed, delivered, settled …) with time, who,
//                     and location when the Driver App sends one.
//
//  Money columns are decimal(12,2): exact to the piastre, up to
//  9,999,999,999.99 EGP.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('seq');                 // 1, 2, 3 … per company
            $table->string('number', 20);                   // "T-00042" — what people read and search

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_route_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('client_request_id')->nullable()->index(); // Step 5
            $table->boolean('is_hired')->default(false);    // copied from the vehicle at creation

            $table->string('status', 12)->default('planned');
            $table->dateTime('loading_at');
            $table->string('cargo', 200)->nullable();
            $table->text('notes')->nullable();

            $table->unsignedInteger('km');                  // round trip, copied from the route
            $table->decimal('planned_hours', 5, 1)->nullable();

            $table->decimal('freight_price', 12, 2);
            $table->string('price_source', 10)->default('rate_card'); // rate_card | manual
            $table->boolean('client_pays_cash')->default(false);
            $table->decimal('custody_planned', 12, 2)->default(0);
            $table->json('standard_budget')->nullable();    // {category_id: amount} copied from the route

            $table->string('transfer_policy', 10);          // approval | limit | auto
            $table->decimal('auto_transfer_limit', 12, 2)->default(0);

            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('custody_issued_at')->nullable();
            $table->dateTime('loading_started_at')->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->string('pod_path')->nullable();         // proof-of-delivery photo (trip_files disk)
            $table->string('pod_receiver', 120)->nullable();
            $table->string('cancel_reason', 250)->nullable();

            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'seq']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'loading_at']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['vehicle_id', 'status']);
            $table->index(['driver_id', 'status']);
        });

        Schema::create('trip_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                     // extra | deduction
            $table->string('label', 120);
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('trip_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->restrictOnDelete(); // null = personal
            $table->boolean('is_personal')->default(false);
            $table->string('paid_from', 12);                // custody | collections | own_pocket | company
            $table->decimal('amount', 12, 2);
            $table->string('note', 250)->nullable();
            $table->string('receipt_path')->nullable();
            $table->dateTime('spent_at');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('source', 8)->default('office');  // driver | office
            $table->unsignedBigInteger('wallet_transfer_id')->nullable()->index(); // paid from collections
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trip_id', 'expense_category_id']);
            $table->index(['company_id', 'spent_at']);
        });

        Schema::create('trip_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->dateTime('received_at');
            $table->string('recorded_by', 8);               // driver | client | office
            $table->string('note', 250)->nullable();
            $table->string('receipt_path')->nullable();

            $table->dateTime('driver_confirmed_at')->nullable();
            $table->dateTime('client_confirmed_at')->nullable();
            $table->dateTime('disputed_at')->nullable();
            $table->string('disputed_by', 8)->nullable();   // driver | client
            $table->string('dispute_note', 250)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 10)->nullable();   // accepted | cancelled

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('trip_id');
            $table->index(['company_id', 'disputed_at']);
            $table->index(['company_id', 'client_confirmed_at']);
        });

        Schema::create('wallet_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->string('from_wallet', 12)->default('collections');
            $table->string('to_wallet', 12)->default('custody');
            $table->decimal('amount', 12, 2);
            $table->string('reason', 250);
            $table->string('status', 10);                   // pending | approved | auto | rejected | cancelled
            $table->string('policy', 10);                   // the trip's policy when it was asked
            $table->decimal('policy_limit', 12, 2)->nullable();
            $table->string('requested_by_type', 8);         // driver | user
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->string('requested_by_name', 120)->nullable();
            $table->dateTime('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 250)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('trip_expense_id')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'status', 'reviewed_at']);
            $table->index('trip_id');
        });

        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('wallet', 12);                   // custody | collections | advances | pocket
            $table->string('type', 30);
            $table->decimal('amount', 12, 2);               // + = more in the driver's hands / owed
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note', 250)->nullable();
            $table->string('actor_type', 8);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'driver_id', 'wallet']);
            $table->index(['company_id', 'wallet']);
            $table->index(['trip_id', 'wallet']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('driver_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('trip_expense_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 250)->nullable();
            $table->decimal('monthly_instalment', 12, 2)->nullable();
            $table->decimal('repaid_amount', 12, 2)->default(0);
            $table->string('source', 14)->default('manual'); // trip_personal | manual
            $table->string('status', 10)->default('open');   // open | repaid | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'driver_id', 'status']);
        });

        Schema::create('trip_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('custody_balance', 12, 2);
            $table->decimal('collections_balance', 12, 2);
            $table->decimal('pocket_balance', 12, 2);
            $table->decimal('net_amount', 12, 2);           // + driver hands over · − company pays him
            $table->decimal('unconfirmed_amount', 12, 2)->default(0);
            $table->string('note', 250)->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('settled_at');
            $table->timestamps();
        });

        Schema::create('trip_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->dateTime('occurred_at');
            $table->string('actor_type', 8);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('note', 250)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trip_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_events');
        Schema::dropIfExists('trip_settlements');
        Schema::dropIfExists('driver_advances');
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('wallet_transfers');
        Schema::dropIfExists('trip_collections');
        Schema::dropIfExists('trip_expenses');
        Schema::dropIfExists('trip_charges');
        Schema::dropIfExists('trips');
    }
};
