<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\ExpenseCategory;
use App\Models\VehicleType;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CompanyDefaults (what every company starts with)
//  Location: app/Services/CompanyDefaults.php
//
//  ensure($companyId) makes sure a company has:
//    · its settings row (with the default business rules), and
//    · the 10 standard expense categories of Scope §6.6, and
//    · the 12 standard truck types (from the Trucks.xlsx list).
//  Safe to call any number of times — nothing is created twice and
//  nothing a company changed is overwritten. Called when a company
//  is created, by the demo seeder, and whenever the settings, routes
//  or categories screens open (so companies created in Step 1 get
//  them automatically).
// ══════════════════════════════════════════════════════════════════

final class CompanyDefaults
{
    public static function ensure(int $companyId): CompanySetting
    {
        $settings = CompanySetting::for($companyId);

        $existing = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('code')->pluck('code')->all();

        $sort = 10;
        foreach (ExpenseCategory::STANDARD as $code => [$ar, $en, $icon, $cash]) {
            if (! in_array($code, $existing, true)) {
                ExpenseCategory::query()->create([
                    'company_id' => $companyId, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en,
                    'icon' => $icon, 'cash_percent' => $cash, 'is_system' => true, 'sort' => $sort,
                ]);
            }
            $sort += 10;
        }

        $haveTypes = VehicleType::query()->withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('code')->pluck('code')->all();
        $sort = 10;
        foreach (VehicleType::STANDARD as $code => [$ar, $en]) {
            if (! in_array($code, $haveTypes, true)) {
                VehicleType::query()->create([
                    'company_id' => $companyId, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'is_system' => true, 'sort' => $sort,
                ]);
            }
            $sort += 10;
        }

        return $settings;
    }
}
