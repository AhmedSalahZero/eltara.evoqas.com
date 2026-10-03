<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — CompanySetting (a company's business rules)
//  Location: app/Models/CompanySetting.php
//
//  One row per company (Scope §6.15). Read it with
//      CompanySetting::for($companyId)
//  which creates it with the defaults the first time.
//  Screen: Office → Company settings (Office\SettingsController).
// ══════════════════════════════════════════════════════════════════

class CompanySetting extends Model
{
    use BelongsToCompany;

    public const POLICIES = ['approval', 'limit', 'auto'];

    public const SPLIT_RULES = ['hours', 'start', 'delivery'];

    public const GA_BASES = ['own_km', 'all_km'];

    protected $fillable = [
        'company_id', 'default_transfer_policy', 'auto_transfer_limit', 'custody_buffer_percent', 'over_budget_percent', 'fuel_flag_percent', 'personal_spend_to_advance',
        'month_split_rule', 'ga_basis', 'ga_rate_estimate',
        'receipt_photo_required', 'capture_location', 'offline_mode', 'max_hours_without_sync',
        'diesel_price', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'auto_transfer_limit'       => 'float',
            'custody_buffer_percent'    => 'float',
            'over_budget_percent'       => 'float',
            'fuel_flag_percent'         => 'float',
            'personal_spend_to_advance' => 'boolean',
            'ga_rate_estimate'          => 'float',
            'receipt_photo_required'    => 'boolean',
            'capture_location'          => 'boolean',
            'offline_mode'              => 'boolean',
            'max_hours_without_sync'    => 'integer',
            'diesel_price'              => 'float',
        ];
    }

    public static function for(int $companyId): self
    {
        $settings = static::query()->withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId]);

        // A row just created only holds company_id in memory: read it back
        // so the database defaults (policy "limit", 1,000 EGP …) are there.
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }
}
