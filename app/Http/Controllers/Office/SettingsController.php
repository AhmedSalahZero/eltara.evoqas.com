<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\CargoType;
use App\Models\ExpenseCategory;
use App\Models\VehicleType;
use App\Services\CompanyDefaults;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\SettingsController ( /office/settings )
//  Location: app/Http/Controllers/Office/SettingsController.php
//
//  Company settings (Scope §6.15) — the demo's four panels:
//    Wallets & approvals (incl. the over-budget flag %, Step 3) ·
//    True profit & month close · Driver app ·
//    General (default language / theme, diesel price, currency, and
//    the expense categories).
//  These are the company's DEFAULTS; Step 3 lets a single trip use a
//  different transfer policy. "A trip cannot close before settlement
//  and proof of delivery" is a fixed rule, shown but not switchable.
//  Every change is written to the audit log (before / after).
//
//  Expense categories: add your own, rename, change the cash share,
//  hide. The 10 standard ones can be renamed or hidden, not deleted.
//
//  Permissions: settings.view to open, settings.edit to change.
// ══════════════════════════════════════════════════════════════════

class SettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $company = $request->user('web')->company;
        $settings = CompanyDefaults::ensure($company->id);

        return Inertia::render('Office/Settings/Index', [
            'settings'   => $settings->only($settings->getFillable()) + [
                'default_language' => $company->default_language,
                'default_theme'    => $company->default_theme,
            ],
            'categories' => ExpenseCategory::query()->ordered()->get()->map(fn (ExpenseCategory $c) => [
                'id' => $c->id, 'code' => $c->code, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'name' => $c->displayName(),
                'icon' => $c->icon, 'cash_percent' => $c->cash_percent, 'is_system' => $c->is_system, 'is_active' => $c->is_active,
            ])->values(),
            'vehicle_types' => VehicleType::query()->ordered()->get()->map(fn (VehicleType $t) => [
                'id' => $t->id, 'name_ar' => $t->name_ar, 'name_en' => $t->name_en, 'name' => $t->displayName(), 'is_system' => $t->is_system, 'is_active' => $t->is_active,
            ])->values(),
            'cargo_types' => CargoType::query()->ordered()->get()->map(fn (CargoType $c) => [
                'id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'name' => $c->displayName(), 'is_active' => $c->is_active,
            ])->values(),
            'icons'      => array_values(array_unique(array_column(ExpenseCategory::STANDARD, 2))),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $company = $request->user('web')->company;
        $settings = CompanyDefaults::ensure($company->id);

        $data = $request->validate([
            'default_transfer_policy'   => ['required', Rule::in(CompanySetting::POLICIES)],
            'auto_transfer_limit'       => ['required', 'numeric', 'min:0', 'max:10000000'],
            'custody_buffer_percent'    => ['required', 'numeric', 'min:0', 'max:100'],
            'over_budget_percent'       => ['sometimes', 'required', 'numeric', 'min:0', 'max:500'],
            'fuel_flag_percent'         => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'personal_spend_to_advance' => ['required', 'boolean'],
            'month_split_rule'          => ['required', Rule::in(CompanySetting::SPLIT_RULES)],
            'ga_basis'                  => ['required', Rule::in(CompanySetting::GA_BASES)],
            'ga_rate_estimate'          => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'receipt_photo_required'    => ['required', 'boolean'],
            'capture_location'          => ['required', 'boolean'],
            'offline_mode'              => ['required', 'boolean'],
            'max_hours_without_sync'    => ['required', 'integer', Rule::in([6, 12, 24, 48])],
            'diesel_price'              => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'currency'                  => ['required', Rule::in(['EGP'])],
            'default_language'          => ['required', 'in:ar,en'],
            'default_theme'             => ['required', 'in:dark,light'],
        ]);

        // The company-wide transfer rule is the same power as a trip's: it needs "Edit transfer rules".
        if (! $request->user('web')->can('trips.edit_policy')
            && ($data['default_transfer_policy'] !== $settings->default_transfer_policy || (float) $data['auto_transfer_limit'] !== (float) $settings->auto_transfer_limit)) {
            return back()->with('error', __('errors.policy_needs_permission'));
        }

        DB::transaction(function () use ($data, $settings, $company) {
            $companyFields = ['default_language', 'default_theme'];
            $before = $settings->only($settings->getFillable()) + $company->only($companyFields);

            $settings->fill(collect($data)->except($companyFields)->all())->save();
            $company->fill(collect($data)->only($companyFields)->all())->save();

            $after = $settings->fresh()->only($settings->getFillable()) + $company->only($companyFields);
            $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));

            if ($changed) {
                Audit::record('settings.updated', $settings, [
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after'  => array_intersect_key($after, array_flip($changed)),
                ]);
            }
        });

        return back()->with('success', __('common.saved'));
    }

    // ── Expense categories ─────────────────────────────────────────

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $this->validatedCategory($request);
        $category = ExpenseCategory::query()->create($data + ['is_system' => false, 'is_active' => true, 'sort' => 500]);
        Audit::record('expense_category.created', $category, ['after' => $data]);

        return back()->with('success', __('common.saved'));
    }

    public function updateCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $data = $this->validatedCategory($request) + ['is_active' => $request->boolean('is_active', true)];
        $category->fill($data);
        $changes = $category->getDirty();
        $category->save();

        if ($changes) {
            Audit::record('expense_category.updated', $category, ['after' => $changes]);
        }

        return back()->with('success', __('common.saved'));
    }

    private function validatedCategory(Request $request): array
    {
        return $request->validate([
            'name_ar'      => ['required', 'string', 'max:80'],
            'name_en'      => ['nullable', 'string', 'max:80'],
            'icon'         => ['required', Rule::in(array_column(ExpenseCategory::STANDARD, 2))],
            'cash_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
    }
}
