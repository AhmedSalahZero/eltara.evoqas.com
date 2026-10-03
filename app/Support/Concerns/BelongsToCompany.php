<?php

namespace App\Support\Concerns;

use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  El Tara — BelongsToCompany (company data isolation)
//  Location: app/Support/Concerns/BelongsToCompany.php
//
//  Put `use BelongsToCompany;` on every model that holds company data
//  (drivers, customers, and later vehicles, trips, wallets …).
//  Two things then happen automatically:
//
//    1. Every query is filtered to the current company
//       (App\Support\Tenant::id()). Office user, driver or client —
//       nobody can read another company's rows, even by typing
//       another id into the address bar.
//    2. New rows get company_id filled in from the current company.
//
//  Not scoped (Tenant::id() is null): the Super Admin, console
//  commands and queued jobs. Those must choose the company on
//  purpose — Tenant::forCompany($id, fn () => …) or ->where().
//
//  Scale note: every company table has an index starting with
//  company_id, so this filter is also what keeps queries fast when
//  the platform holds 500 companies.
// ══════════════════════════════════════════════════════════════════

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {
            if (empty($model->company_id) && ($id = Tenant::id()) && $id !== Tenant::ORPHAN) {
                $model->company_id = $id;
            }
        });

        static::addGlobalScope('company', function (Builder $builder) {
            $id = Tenant::id();

            if ($id === null) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.company_id', $id);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
