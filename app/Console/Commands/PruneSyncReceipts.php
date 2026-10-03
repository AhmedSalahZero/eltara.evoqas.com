<?php

namespace App\Console\Commands;

use App\Models\SyncReceipt;
use Illuminate\Console\Command;

// ══════════════════════════════════════════════════════════════════
//  El Tara — sync:prune-receipts
//  Location: app/Console/Commands/PruneSyncReceipts.php
//
//  Runs daily (routes/console.php). Deletes "already received"
//  receipts older than 90 days (config/eltara.php → sync). A phone
//  retries a stuck upload within minutes or days, never months, so
//  old receipts are no longer needed — and with thousands of drivers
//  the table would otherwise grow forever. The entries themselves
//  (expenses, collections …) are of course kept.
// ══════════════════════════════════════════════════════════════════

class PruneSyncReceipts extends Command
{
    protected $signature = 'sync:prune-receipts';

    protected $description = 'Delete offline-sync receipts older than the keep period';

    public function handle(): int
    {
        $days = (int) config('eltara.sync.keep_receipts_days', 90);

        $deleted = SyncReceipt::query()->where('processed_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} old sync receipt(s).");

        return self::SUCCESS;
    }
}
