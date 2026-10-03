<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: notifications
//  Location: database/migrations/2026_09_30_000005_create_notifications_table.php
//
//  In-app notifications (Scope §11 — Phase 1 channel). Laravel's
//  standard table: one row per notification per person. Works for
//  office users, drivers and client users alike (notifiable_type
//  says which).
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
