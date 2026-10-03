<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — GaEntry (one G&A line of a month)
//  Location: app/Models/GaEntry.php
//
//  Scope §6.13 step 1: the CFO enters the month's general &
//  administrative costs (drivers' fixed salaries, office salaries,
//  depreciation, maintenance & spare parts, tyres, insurance,
//  licences & fees, rent, other admin) — by hand or from Excel.
//  `month` is the first day of the month. `code` is one of STANDARD
//  below, or null when the line has its own name.
//  A closed month's lines cannot change (MonthCloseService).
//  A standard line exists ONCE per company and month (unique key on
//  company_id + month + code); lines with their own name (code null)
//  can repeat.
// ══════════════════════════════════════════════════════════════════

class GaEntry extends Model
{
    use BelongsToCompany;

    /** code => [Arabic, English] */
    public const STANDARD = [
        'driver_salaries' => ['رواتب السائقين الثابتة', 'Drivers\' fixed salaries'],
        'office_salaries' => ['رواتب المكتب', 'Office salaries'],
        'depreciation'    => ['الإهلاك', 'Depreciation'],
        'maintenance'     => ['الصيانة وقطع الغيار', 'Maintenance & spare parts'],
        'tyres'           => ['الإطارات', 'Tyres'],
        'insurance'       => ['التأمين', 'Insurance'],
        'licences'        => ['التراخيص والرسوم', 'Licences & fees'],
        'rent'            => ['الإيجار', 'Rent'],
        'other_admin'     => ['مصروفات إدارية أخرى', 'Other admin'],
    ];

    protected $fillable = ['company_id', 'month', 'code', 'label', 'amount', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    /** The line's name in the current language (standard lines are translated, own lines show as typed). */
    public function displayLabel(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($this->code && isset(self::STANDARD[$this->code])) {
            return self::STANDARD[$this->code][$locale === 'en' ? 1 : 0];
        }

        return $this->label;
    }
}
