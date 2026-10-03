<?php

namespace App\Enums;

// ══════════════════════════════════════════════════════════════════
//  El Tara — UserRole
//  Location: app/Enums/UserRole.php
//
//  The three kinds of OFFICE account (table: users). Stored as a plain
//  string in users.role.
//
//    SuperAdmin   → the platform owner. No company. Creates companies
//                   and sets their limits; never sees their finances.
//    CompanyAdmin → a company's administrator. Always holds every
//                   permission of the company (Scope §5).
//    OfficeUser   → everyone else in the office (fleet manager, CFO,
//                   dispatcher …). Holds only the permissions granted
//                   to them by name.
//
//  Drivers and client-portal users are separate account types with
//  their own tables and sign-in (App\Models\Driver, ClientUser).
// ══════════════════════════════════════════════════════════════════

enum UserRole: string
{
    case SuperAdmin   = 'super_admin';
    case CompanyAdmin = 'company_admin';
    case OfficeUser   = 'office_user';

    public function label(?string $locale = null): string
    {
        $ar = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::SuperAdmin   => $ar ? 'مدير المنصة' : 'Platform admin',
            self::CompanyAdmin => $ar ? 'مدير الشركة' : 'Company admin',
            self::OfficeUser   => $ar ? 'مستخدم مكتب' : 'Office user',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
