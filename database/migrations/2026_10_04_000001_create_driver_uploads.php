<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Step 4 migration: photos uploaded by the Driver App
//  Location: database/migrations/2026_10_04_000001_create_driver_uploads.php
//
//  Photos travel separately from the trip entries (they are big and
//  the signal is weak). The phone gives every photo its own uuid,
//  uploads it when it can, and the entry that uses the photo
//  (expense, cash, delivery) only carries that uuid. Uploading the
//  same uuid twice is harmless: the first file is kept.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('path', 250);
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();

            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_uploads');
    }
};
