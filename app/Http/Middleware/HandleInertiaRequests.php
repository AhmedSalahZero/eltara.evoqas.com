<?php

namespace App\Http\Middleware;

use App\Models\ClientUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

// ══════════════════════════════════════════════════════════════════
//  El Tara — HandleInertiaRequests
//  Location: app/Http/Middleware/HandleInertiaRequests.php
//
//  What every office / admin / client screen receives as shared props
//  (usePage().props in Vue):
//
//    auth.portal   → 'admin' | 'office' | 'client' | null (guest)
//    auth.user     → the signed-in person: name, initials, role,
//                    language, theme, permission keys
//    auth.company  → their company: name, limits, subscription state
//                    (read-only / expiring soon / days left)
//    auth.customer → client portal only: the client's own company
//    flash         → success / error / warning / info messages, and a
//                    driver's new PIN (shown once)
//    locale        → 'ar' | 'en' for this request
//    support       → who to contact to renew (subscription banner)
//
//  The Driver App does not use this: it is a separate offline app
//  that talks to /driver/api/* (see routes/driver.php).
//
//  Everything is a closure, so it is only worked out when a page is
//  actually drawn — never for redirects.
// ══════════════════════════════════════════════════════════════════

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => fn () => $this->auth($request),

            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                // A driver's new PIN, shown once after creating him or resetting it.
                'pin'     => $request->session()->get('pin'),
                'info'    => $request->session()->get('info'),
            ],

            'locale' => fn () => app()->getLocale(),

            // The company's time zone: every screen shows dates and times in it, whatever the browser is set to.
            'timezone' => config('app.timezone'),

            // The bell (Scope §11): the latest lines and how many are unread.
            'notifications' => fn () => $this->notifications($request),

            'support' => [
                'email' => config('eltara.subscription.support_email'),
                'phone' => config('eltara.subscription.support_phone'),
            ],

            'app' => [
                'name' => config('app.name'),
                'env'  => config('app.env'),
            ],
        ];
    }

    private function notifications(Request $request): array
    {
        $who = $request->is('client', 'client/*') ? $request->user('client') : $request->user('web');

        if (! $who) {
            return ['unread' => 0, 'items' => []];
        }

        return [
            'unread' => $who->unreadNotifications()->count(),
            'items'  => $who->notifications()->latest()->limit(15)->get()->map(fn ($n) => [
                'id'     => $n->id,
                'key'    => $n->data['key'] ?? '',
                'params' => $n->data['params'] ?? [],
                'url'    => $n->data['url'] ?? null,
                'read'   => $n->read_at !== null,
                'at'     => $n->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function auth(Request $request): array
    {
        $client = $request->is('client', 'client/*') ? $request->user('client') : null;
        $user = $client ? null : $request->user('web');

        if ($client) {
            $client->loadMissing('company', 'customer');

            return [
                'portal'   => 'client',
                'user'     => [
                    ...$this->person($client),
                    'is_account_admin' => $client->is_account_admin,
                    'permissions'      => [],
                ],
                'company'  => $this->company($client->company),
                'customer' => [
                    'id'   => $client->customer->id,
                    'name' => $client->customer->displayName(),
                ],
            ];
        }

        if (! $user) {
            return ['portal' => null, 'user' => null, 'company' => null, 'customer' => null];
        }

        $user->loadMissing('company');

        return [
            'portal'   => $user->isSuperAdmin() ? 'admin' : 'office',
            'user'     => [
                ...$this->person($user),
                'role'        => $user->role,
                'role_label'  => $user->roleLabel(),
                'job_title'   => $user->job_title,
                'permissions' => $user->permissionKeys(),
            ],
            'company'  => $user->company ? $this->company($user->company) : null,
            'customer' => null,
        ];
    }

    private function person(User|ClientUser $account): array
    {
        return [
            'id'       => $account->id,
            'name'     => $account->name,
            'initials' => $account->initials(),
            'email'    => $account->email,
            'language' => $account->preferredLanguage(),
            'theme'    => $account->preferredTheme(),
        ];
    }

    private function company(Company $company): array
    {
        return [
            'id'                   => $company->id,
            'name'                 => $company->displayName(),
            'status'               => $company->status->value,
            'subscription_ends_at' => $company->subscription_ends_at?->toDateString(),
            'days_left'            => $company->daysUntilExpiry(),
            'expiring_soon'        => $company->isExpiringSoon(),
            'read_only'            => $company->isReadOnly(),
        ];
    }
}
