<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: users (office staff + Super Admin)
//  Location: database/migrations/0001_01_01_000001_create_users_table.php
//
//  Everyone who signs in to the OFFICE side with email + password:
//    super_admin   → the platform owner (company_id is NULL)
//    company_admin → a company's administrator (always holds every
//                    permission, cannot be restricted — Scope §5)
//    office_user   → fleet manager, CFO, dispatcher, treasurer …
//                    each with their own permissions
//
//  Drivers and client-portal users are NOT here — they have their
//  own tables (drivers, client_users) and their own sign-in.
//
//  permissions    → JSON list of keys like "trips.create" granted to
//                   this named user (config/permissions.php). A small
//                   list per user, read once per request.
//  approval_limit → the most this user may approve in ONE wallet
//                   transfer (EGP). Above it → the company admin.
//  theme NULL     → follow the company's default theme.
//
//  Also creates Laravel's password_reset_tokens (used for reset links
//  AND activation links) and sessions tables.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('name', 120);
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable()->index();
            $table->string('password');

            $table->string('role', 20)->index();
            $table->string('job_title', 100)->nullable();
            $table->json('permissions')->nullable();
            $table->decimal('approval_limit', 14, 2)->nullable();

            $table->char('language', 2)->default('ar');
            $table->string('theme', 10)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'role']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
