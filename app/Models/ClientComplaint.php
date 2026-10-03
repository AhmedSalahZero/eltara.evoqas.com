<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ClientComplaint (a complaint and the office's reply)
//  Location: app/Models/ClientComplaint.php
//  status: open → answered (the office replied; it may reply again).
//  About one trip (trip_id) or general (no trip). Scope §6.2, §7.
// ══════════════════════════════════════════════════════════════════

class ClientComplaint extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'customer_id', 'client_user_id', 'trip_id', 'subject', 'body', 'status', 'reply', 'replied_by', 'replied_at'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'client_user_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
