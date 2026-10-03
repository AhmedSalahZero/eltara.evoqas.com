<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: audit_logs
//  Location: database/migrations/2026_09_30_000003_create_audit_logs_table.php
//
//  The permanent record of sensitive actions (Scope §12): approvals,
//  settlements, price edits, month close / re-open, permission and
//  limit changes. Rows are only ever ADDED — never edited or deleted.
//
//  actor_type → user | driver | client | system
//  actor_name → copied at the time, so the log still reads correctly
//               if the person is renamed or removed later.
//  changes    → {"before": {...}, "after": {...}} or any detail.
//
//  Indexed by (company_id, created_at) because the audit screen and
//  reports always read one company's log, newest first.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120)->nullable();

            $table->string('action', 60)->index();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();

            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
