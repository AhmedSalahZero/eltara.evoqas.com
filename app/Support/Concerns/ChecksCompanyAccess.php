<?php

namespace App\Support\Concerns;

use App\Enums\CompanyStatus;
use App\Models\Company;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ChecksCompanyAccess
//  Location: app/Support/Concerns/ChecksCompanyAccess.php
//
//  Shared by every account type that belongs to a company (office
//  users, drivers, client users). One question, one answer:
//  "may this account use El Tara right now?"
//
//  accessDenialReason() returns a translation key (lang/*/errors.php)
//  explaining why not, or null when access is allowed. It is checked
//  at sign-in AND on every request (the portal middleware), so
//  suspending a person or a company takes effect immediately, even
//  for someone already signed in.
//
//  Note: a company whose subscription has ENDED is not refused here.
//  It becomes read-only instead (App\Http\Middleware\
//  BlockWritesWhenReadOnly) — Scope §4.2.
// ══════════════════════════════════════════════════════════════════

trait ChecksCompanyAccess
{
    public function accessDenialReason(): ?string
    {
        if (! $this->is_active) {
            return 'errors.account_suspended';
        }

        $company = $this->relationLoaded('company')
            ? $this->company
            : Company::query()->find($this->company_id);

        if (! $company) {
            return 'errors.account_orphaned';
        }

        if ($company->status === CompanyStatus::Suspended) {
            return 'errors.company_suspended';
        }

        return null;
    }
}
