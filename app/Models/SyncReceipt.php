<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SyncReceipt
//  Location: app/Models/SyncReceipt.php
//
//  "The server has already received offline entry <uuid>."
//  Written by App\Sync\SyncProcessor for every entry the Driver App
//  uploads, so an entry that arrives twice is recognised and never
//  recorded twice. See the sync_receipts migration and
//  docs/STEP_01_FOUNDATION.md §6.
// ══════════════════════════════════════════════════════════════════

class SyncReceipt extends Model
{
    public $timestamps = false;

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'uuid',
        'company_id',
        'driver_id',
        'type',
        'status',
        'result',
        'recorded_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'result'       => 'array',
            'recorded_at'  => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
