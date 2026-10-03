<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — AuditLog
//  Location: app/Models/AuditLog.php
//
//  One row of the permanent audit trail (Scope §12). Written only
//  through App\Support\Audit::record(). Rows are never updated or
//  deleted — save() on an existing row and delete() both refuse.
// ══════════════════════════════════════════════════════════════════

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'actor_type',
        'actor_id',
        'actor_name',
        'action',
        'subject_type',
        'subject_id',
        'changes',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'changes'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit log rows cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Audit log rows cannot be deleted.'));
    }
}
