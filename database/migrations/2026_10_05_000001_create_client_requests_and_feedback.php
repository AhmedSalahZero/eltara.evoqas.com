<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Step 5 migration: client requests, ratings, complaints
//  Location: database/migrations/2026_10_05_000001_create_client_requests_and_feedback.php
//
//  client_requests   — a trip request a client sent from his portal
//                      (Scope §6.2, §7): route, loading time, how many
//                      trucks, cargo, notes. The expected price is the
//                      client's agreed price, copied at the moment of
//                      the request so it never changes afterwards.
//                      status: new → approved (trucks assigned, one trip
//                      per truck — trips.client_request_id) | declined
//                      (with a reason the client reads) | cancelled
//                      (the client withdrew it while it was new).
//  trip_ratings      — 1–5 stars and a comment, once per trip.
//  client_complaints — a complaint (about a trip or in general) and the
//                      office's reply: open → answered.
//
//  trips.client_request_id already exists (Step 3) and now gets filled.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('seq');                       // 1, 2, 3 … per company
            $table->string('number', 20);                         // "R-00007"
            $table->foreignId('requested_by')->nullable()->constrained('client_users')->nullOnDelete();
            $table->foreignId('trip_route_id')->constrained()->restrictOnDelete();
            $table->dateTime('loading_at');
            $table->unsignedSmallInteger('trucks_count')->default(1);
            $table->foreignId('cargo_type_id')->nullable()->constrained('cargo_types')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('expected_unit_price', 12, 2)->nullable();
            $table->string('status', 10)->default('new');         // new | approved | declined | cancelled
            $table->string('decline_reason', 250)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'seq']);
            $table->index(['company_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('trip_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->unique('trip_id');
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('client_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 150);
            $table->text('body');
            $table->string('status', 10)->default('open');        // open | answered
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('replied_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_complaints');
        Schema::dropIfExists('trip_ratings');
        Schema::dropIfExists('client_requests');
    }
};
