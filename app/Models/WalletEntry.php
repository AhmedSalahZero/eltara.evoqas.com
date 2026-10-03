<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — WalletEntry (one line of the wallet ledger)
//  Location: app/Models/WalletEntry.php
//
//  Every movement of money in a driver's wallets is one row. A
//  balance is the SUM of amount for that wallet — nothing else.
//
//  WALLETS
//    custody     company money for road costs (+ = in the driver's hands)
//    collections client money held for the company (+ = in his hands)
//    advances    personal advances he owes (+ = he owes more)
//    pocket      his own money he spent on the trip (+ = the company
//                owes him; refunded at settlement)
//
//  Rows are PERMANENT, like the audit log: they cannot be edited or
//  deleted. When an expense is corrected or a transfer is cancelled,
//  a new correcting row is added (type ending in "_correction"), so
//  a statement always adds up and shows what happened.
//  Written only by App\Services\Trips\WalletLedger.
// ══════════════════════════════════════════════════════════════════

class WalletEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const WALLETS = ['custody', 'collections', 'advances', 'pocket'];

    protected $fillable = [
        'company_id', 'driver_id', 'trip_id', 'wallet', 'type', 'amount', 'source_type', 'source_id', 'source_key',
        'note', 'actor_type', 'actor_id', 'actor_name', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'float',
            'occurred_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    /**
     * The "this movement was already posted" key (unique in the database).
     * One record can post a given wallet + type only ONCE as an original row, so a repeated or racing
     * call cannot double-post. Corrections ("…_correction") may repeat and a movement with no source
     * has nothing to repeat, so those have no key (null never clashes).
     */
    public static function sourceKey(?string $sourceType, $sourceId, string $wallet, string $type): ?string
    {
        if ($sourceType === null || $sourceId === null || str_ends_with($type, '_correction')) {
            return null;
        }

        return "{$sourceType}:{$sourceId}:{$wallet}:{$type}";
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Wallet ledger rows cannot be changed — post a correcting row instead.'));
        static::deleting(fn () => throw new \LogicException('Wallet ledger rows cannot be deleted — post a correcting row instead.'));
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
