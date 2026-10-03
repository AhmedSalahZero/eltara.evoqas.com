<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Migration: cache + cache_locks
//  Location: database/migrations/0001_01_01_000002_create_cache_table.php
//
//  Laravel's standard cache tables, used when CACHE_STORE=database
//  (the setting on your computer). On the live server we switch the
//  cache to Redis for speed (docs/ARCHITECTURE.md) and these tables
//  simply stay empty.
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
