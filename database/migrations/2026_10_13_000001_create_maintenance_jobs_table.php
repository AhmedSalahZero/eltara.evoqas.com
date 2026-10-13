<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Vehicle maintenance log
//  Location: database/migrations/2026_10_13_000001_create_maintenance_jobs_table.php
//
//  One row is one piece of work on a truck:
//    periodic  oil, tyres, filters (or other), with a km and/or day interval
//    emergency  a breakdown
//  An open job is what holds the truck in maintenance. The cost stays
//  in this log: it is not a trip expense and it is not a G&A line.
//  Money is decimal, exact to the piastre. No soft delete.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12);                         // periodic | emergency
            $table->string('category', 12);                     // oil | tyres | filters | other
            $table->string('status', 8);                        // open | done
            $table->dateTime('opened_at');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('workshop', 120)->nullable();
            $table->string('note', 250)->nullable();
            $table->unsignedInteger('interval_km')->nullable();
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'opened_at']);
            $table->index(['company_id', 'vehicle_id', 'status']);
        });

        // SQLite (the test database) cannot add a CHECK the same way, and
        // the service already refuses any other value.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `maintenance_jobs` ADD CONSTRAINT `chk_maintenance_jobs_kind` CHECK (`kind` IN ('periodic', 'emergency'))");
            DB::statement("ALTER TABLE `maintenance_jobs` ADD CONSTRAINT `chk_maintenance_jobs_category` CHECK (`category` IN ('oil', 'tyres', 'filters', 'other'))");
            DB::statement("ALTER TABLE `maintenance_jobs` ADD CONSTRAINT `chk_maintenance_jobs_status` CHECK (`status` IN ('open', 'done'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_jobs');
    }
};
