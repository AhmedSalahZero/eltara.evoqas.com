<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Extra driver and customer details (Step 2)
//  Location: database/migrations/2026_10_01_000003_add_step2_fields_to_drivers_and_customers.php
//
//  drivers   + base_salary (for the fixed pay bases; enters G&A at
//              month close, Step 6), notes
//  customers + contact person, email, address, tax number, notes
//  Adds to the Step 1 tables — nothing existing is changed or lost.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->decimal('base_salary', 10, 2)->nullable()->after('pay_basis');
            $table->text('notes')->nullable()->after('joined_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('contact_name', 120)->nullable()->after('may_pay_driver_cash');
            $table->string('contact_email', 150)->nullable()->after('contact_phone');
            $table->string('address', 250)->nullable()->after('contact_email');
            $table->string('tax_number', 30)->nullable()->after('address');
            $table->text('notes')->nullable()->after('tax_number');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_email', 'address', 'tax_number', 'notes']);
        });
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'notes']);
        });
    }
};
