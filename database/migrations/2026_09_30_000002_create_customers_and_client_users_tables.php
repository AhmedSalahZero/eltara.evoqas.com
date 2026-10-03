<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: customers + client_users
//  Location: database/migrations/2026_09_30_000002_create_customers_and_client_users_tables.php
//
//  customers    → the transport company's clients (shippers).
//                 Only the basics now; rate cards, routes and the
//                 rest of the customer record arrive in Step 2.
//
//  client_users → staff of a customer who sign in to the CLIENT
//                 PORTAL with email + password. They see only their
//                 own customer's requests, shipments and statements
//                 (Scope §7). They do NOT count toward the transport
//                 company's office-user limit (Scope §17 #5).
//                 is_account_admin → may add colleagues (Scope §7).
//
//  client_password_reset_tokens → reset / activation links for
//                 client users (kept apart from office users').
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('may_pay_driver_cash')->default(false);
            $table->string('contact_phone', 20)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('client_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->string('name', 120);
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('password');
            $table->boolean('is_account_admin')->default(false);

            $table->char('language', 2)->default('ar');
            $table->string('theme', 10)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
            $table->index('company_id');
        });

        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_password_reset_tokens');
        Schema::dropIfExists('client_users');
        Schema::dropIfExists('customers');
    }
};
