<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Invoice (an invoice NUMBER from the company's ERP)
//  Location: app/Models/Invoice.php
//
//  Scope §6.11. El Tara does not issue invoices or track payments —
//  it only links the number to trips. One invoice can cover several
//  trips of the SAME customer (5 trucks on one order). A trip
//  belongs to at most one invoice (trips.invoice_id).
//  Linked through App\Services\Invoices\InvoiceService.
// ══════════════════════════════════════════════════════════════════

class Invoice extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'customer_id', 'number', 'issued_on', 'note', 'created_by'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
