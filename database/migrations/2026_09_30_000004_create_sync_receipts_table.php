<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: sync_receipts
//  Location: database/migrations/2026_09_30_000004_create_sync_receipts_table.php
//
//  The anti-duplicate memory of the offline sync (Scope §12
//  "duplicate submissions are prevented").
//
//  Every entry the Driver App saves on the phone gets a unique id
//  (uuid) at the moment it is recorded. When the phone uploads, the
//  server writes one receipt per uuid. If the same entry arrives
//  again — the signal dropped before the phone heard "OK", so it
//  retried — the unique index on uuid makes the server recognise it
//  and answer "already have it" instead of recording it twice.
//
//  recorded_at → when the driver did it (phone clock)
//  processed_at→ when the server received it
//  Old receipts are pruned after 90 days (sync:prune-receipts).
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->string('type', 60);
            $table->string('status', 20);
            $table->json('result')->nullable();

            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('processed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_receipts');
    }
};
