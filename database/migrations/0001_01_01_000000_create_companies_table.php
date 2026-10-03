<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: companies
//  Location: database/migrations/0001_01_01_000000_create_companies_table.php
//
//  The transport companies (tenants). Created only by the Super
//  Admin (Scope §4.1). Everything a company owns carries its
//  company_id, so one company can never see another's data.
//
//  Limits (Scope §4.2):
//    office_users_limit    → active office users, the admin included
//    driver_accounts_limit → active driver accounts (a separate limit)
//  Suspended users / drivers do not count toward either limit.
//
//  Subscription:
//    status                → active | trial | suspended
//                            (suspended = nobody can sign in)
//    subscription_ends_at  → after this date the company is READ-ONLY
//                            (they can look, not record)
//    expiry_notified_at    → last reminder email (30 days before)
//
//  created_by points to users, which is created in the next
//  migration, so that foreign key is added there.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_en', 150);

            $table->string('status', 20)->default('trial')->index();
            $table->unsignedSmallInteger('office_users_limit')->default(5);
            $table->unsignedSmallInteger('driver_accounts_limit')->default(15);

            $table->date('subscription_starts_at');
            $table->date('subscription_ends_at')->nullable()->index();
            $table->timestamp('expiry_notified_at')->nullable();

            $table->char('default_language', 2)->default('ar');
            $table->string('default_theme', 10)->default('dark');

            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
