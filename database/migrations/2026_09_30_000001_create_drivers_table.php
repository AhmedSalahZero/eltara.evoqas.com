<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: drivers
//  Location: database/migrations/2026_09_30_000001_create_drivers_table.php
//
//  Drivers sign in to the Driver App (PWA) with their MOBILE number
//  and a 4-digit PIN (Scope §8.2) — never with email. They are a
//  separate account type with their own limit per company
//  (companies.driver_accounts_limit, Scope §17 #2).
//
//  mobile   → stored in one form (01XXXXXXXXX, see App\Support\
//             EgyptPhone) and unique across the platform, so the
//             sign-in screen knows exactly who is signing in.
//  pin      → hashed, like a password. Never stored readable.
//  last_sync_at → the last time the phone uploaded its offline
//             entries. The office is alerted when a driver has not
//             synced for too long (Scope §11).
//
//  Vehicle assignment and the rest of the driver record arrive with
//  Step 2 (master data) as a follow-up migration.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('name', 120);
            $table->string('mobile', 11)->unique();
            $table->string('pin');

            $table->string('license_number', 40)->nullable();
            $table->date('license_expires_at')->nullable();
            $table->string('pay_basis', 20)->default('fixed');
            $table->date('joined_at')->nullable();

            $table->char('language', 2)->default('ar');
            $table->string('theme', 10)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'license_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
