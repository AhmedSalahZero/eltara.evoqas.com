<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ExpenseCategory (a line trip costs are recorded under)
//  Location: app/Models/ExpenseCategory.php
//
//  The 10 standard categories of Scope §6.6 (STANDARD below) are
//  created for every company (App\Services\CompanyDefaults) and
//  cannot be deleted — only hidden. Companies add their own.
//  cash_percent: the share the driver pays in cash from custody.
// ══════════════════════════════════════════════════════════════════

class ExpenseCategory extends Model
{
    use BelongsToCompany;

    /** code => [Arabic, English, icon, cash %] — Scope §6.6 */
    public const STANDARD = [
        'fuel'   => ['وقود', 'Fuel', 'fuel', 40],
        'toll'   => ['كارتة ورسوم طرق', 'Road tolls', 'toll', 100],
        'weigh'  => ['ميزان', 'Weigh station', 'scale', 100],
        'allow'  => ['بدل رحلة السائق', 'Driver trip allowance', 'person', 100],
        'labor'  => ['تحميل وتنزيل', 'Loading & unloading labour', 'box', 100],
        'night'  => ['مبيت وانتظار', 'Overnight & waiting', 'bed', 100],
        'fine'   => ['مخالفات', 'Fines', 'ticket', 100],
        'repair' => ['إصلاح على الطريق', 'Road repair', 'wrench', 100],
        'hire'   => ['أجرة سيارة مؤجرة', 'Hired truck fee', 'truck', 0],
        'other'  => ['أخرى', 'Other', 'receipt', 100],
    ];

    protected $fillable = ['company_id', 'code', 'name_ar', 'name_en', 'icon', 'cash_percent', 'is_system', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['cash_percent' => 'integer', 'is_system' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }
}
