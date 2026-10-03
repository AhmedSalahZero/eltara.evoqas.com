<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Services\AccountInvitation;
use App\Services\CompanyOnboarding;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Admin\CompanyController ( /admin/companies )
//  Location: app/Http/Controllers/Admin/CompanyController.php
//
//  The Super Admin's companies screen (Scope §4):
//    index            → list with search, status filter and usage
//                       meters (25 per page)
//    store            → "New company" (App\Services\CompanyOnboarding:
//                       company + admin + activation email)
//    update           → "Edit limits": names, status, limits, dates.
//                       Every change is written to the audit log with
//                       before / after values.
//    resendActivation → a fresh activation email to the company admin
// ══════════════════════════════════════════════════════════════════

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $companies = Company::query()
            ->withUsage()
            ->with('admin')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")))
            ->when(in_array($status, CompanyStatus::values(), true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Companies/Index', [
            'companies' => [
                'data'  => self::rows($companies->getCollection()),
                'links' => $companies->linkCollection(),
                'total' => $companies->total(),
            ],
            'filters'  => ['search' => $search, 'status' => $status],
            'defaults' => self::defaults(),
        ]);
    }

    public function store(StoreCompanyRequest $request, CompanyOnboarding $onboarding): RedirectResponse
    {
        $onboarding->create($request->validated(), $request->user('web'));

        return back()->with('success', __('common.company_created'));
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            $fields = array_keys($request->validated());
            $before = $this->snapshot($company, $fields);

            $company->fill($request->validated());
            $dirty = array_keys($company->getDirty());

            if (array_intersect($dirty, ['subscription_ends_at'])) {
                $company->expiry_notified_at = null;
            }

            $company->save();

            if ($dirty) {
                Audit::record('company.updated', $company, [
                    'before' => array_intersect_key($before, array_flip($dirty)),
                    'after'  => array_intersect_key($this->snapshot($company, $fields), array_flip($dirty)),
                ]);
            }
        });

        return back()->with('success', __('common.company_updated'));
    }

    public function resendActivation(Company $company, AccountInvitation $invitation): RedirectResponse
    {
        $admin = $company->admin()->first();
        abort_unless($admin, 404);

        $invitation->send($admin);

        return back()->with('success', __('common.activation_resent', ['email' => $admin->email]));
    }

    /** Starting values for the "New company" form. */
    public static function defaults(): array
    {
        return [
            'office_users_limit'     => config('eltara.company_defaults.office_users_limit'),
            'driver_accounts_limit'  => config('eltara.company_defaults.driver_accounts_limit'),
            'subscription_starts_at' => today()->toDateString(),
            'subscription_ends_at'   => today()->addDays((int) config('eltara.company_defaults.trial_days'))->toDateString(),
        ];
    }

    /** The same row shape for the dashboard and the companies list. */
    public static function rows(Collection $companies): array
    {
        return $companies->map(fn (Company $c) => [
            'id'                     => $c->id,
            'name'                   => $c->displayName(),
            'name_ar'                => $c->name_ar,
            'name_en'                => $c->name_en,
            'status'                 => $c->status->value,
            'office_used'            => (int) $c->office_used,
            'office_users_limit'     => $c->office_users_limit,
            'drivers_used'           => (int) $c->drivers_used,
            'driver_accounts_limit'  => $c->driver_accounts_limit,
            'subscription_starts_at' => $c->subscription_starts_at?->toDateString(),
            'subscription_ends_at'   => $c->subscription_ends_at?->toDateString(),
            'days_left'              => $c->daysUntilExpiry(),
            'read_only'              => $c->isReadOnly(),
            'expiring_soon'          => $c->isExpiringSoon(),
            'default_language'       => $c->default_language,
            'default_theme'          => $c->default_theme,
            'contact_phone'          => $c->contact_phone,
            'contact_email'          => $c->contact_email,
            'notes'                  => $c->notes,
            'admin'                  => $c->relationLoaded('admin') && $c->admin ? [
                'name'      => $c->admin->name,
                'email'     => $c->admin->email,
                'activated' => $c->admin->isActivated(),
            ] : null,
        ])->values()->all();
    }

    private function snapshot(Company $company, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $company->getAttribute($field);
            $out[$field] = match (true) {
                $value instanceof \BackedEnum        => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default                              => $value,
            };
        }

        return $out;
    }
}
