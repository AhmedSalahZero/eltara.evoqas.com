<?php

namespace App\Enums;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CompanyStatus
//  Location: app/Enums/CompanyStatus.php
//
//  A company's account status, set by the Super Admin (Scope §4.1):
//    Active    → paying customer, full access
//    Trial     → trying El Tara, full access
//    Suspended → nobody in the company can sign in (office, client
//                portal or driver app). Data is kept untouched.
//
//  Separate from the subscription END DATE: when that passes, an
//  active or trial company becomes read-only (Company::isReadOnly()),
//  which is softer than suspended.
// ══════════════════════════════════════════════════════════════════

enum CompanyStatus: string
{
    case Active    = 'active';
    case Trial     = 'trial';
    case Suspended = 'suspended';

    public function label(?string $locale = null): string
    {
        $ar = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Active    => $ar ? 'نشطة' : 'Active',
            self::Trial     => $ar ? 'تجريبية' : 'Trial',
            self::Suspended => $ar ? 'موقوفة' : 'Suspended',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
