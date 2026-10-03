<?php

namespace App\Support;

use App\Models\User;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Permissions (the checker)
//  Location: app/Support/Permissions.php
//
//  Reads config/permissions.php and answers one question: "does this
//  office user hold this permission key?"
//
//  Registered as a Gate::before() hook in AppServiceProvider, so all
//  of Laravel's normal checks use it:
//      $user->can('trips.create')
//      Route::middleware('can:users.edit')
//
//  Rules:
//    · Super Admin    → the platform keys only (never company data).
//    · Company admin  → every company key, always.
//    · Office user    → only the keys ticked for them by name.
//    · Suspended user → nothing.
//    · Unknown key    → not ours: returns null so Laravel's other
//                       gates and policies still decide.
//
//  The answer is remembered for the rest of the request, so a page
//  that checks twenty keys reads the list once.
// ══════════════════════════════════════════════════════════════════

final class Permissions
{
    /** @var array<int, list<string>> */
    private static array $memo = [];

    /** Every company key, flat: ['dashboard.view', 'trips.view', …]. */
    public static function companyKeys(): array
    {
        $keys = [];

        foreach (config('permissions.features', []) as $feature => $def) {
            foreach ($def['actions'] as $action) {
                $keys[] = "{$feature}.{$action}";
            }
        }

        return $keys;
    }

    public static function platformKeys(): array
    {
        return config('permissions.platform', []);
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::companyKeys(), true) || in_array($key, self::platformKeys(), true);
    }

    /** Keep only real company keys, in matrix order, without repeats. */
    public static function clean(array $keys): array
    {
        return array_values(array_intersect(self::companyKeys(), $keys));
    }

    /** The keys this user holds. */
    public static function for(User $user): array
    {
        if (isset(self::$memo[$user->id])) {
            return self::$memo[$user->id];
        }

        return self::$memo[$user->id] = match (true) {
            ! $user->is_active       => [],
            $user->isSuperAdmin()    => self::platformKeys(),
            $user->isCompanyAdmin()  => self::companyKeys(),
            default                  => self::clean($user->permissions ?? []),
        };
    }

    /** Gate::before hook: true / false for our keys, null for anything else. */
    public static function check(mixed $user, string $ability): ?bool
    {
        if (! $user instanceof User || ! self::exists($ability)) {
            return null;
        }

        return in_array($ability, self::for($user), true);
    }

    /**
     * The grid for the Users & Permissions screen, in the current
     * language: [['key' => 'trips', 'label' => 'Trips', 'actions' => [['key' => 'trips.view', 'action' => 'view', 'label' => 'View'], …]], …]
     */
    public static function matrix(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $actions = config('permissions.actions', []);
        $rows = [];

        foreach (config('permissions.features', []) as $feature => $def) {
            $rows[] = [
                'key'     => $feature,
                'label'   => $def[$locale] ?? $def['en'],
                'actions' => array_map(fn (string $action) => [
                    'key'    => "{$feature}.{$action}",
                    'action' => $action,
                    'label'  => $actions[$action][$locale] ?? $action,
                ], $def['actions']),
            ];
        }

        return $rows;
    }

    /** Forget remembered answers (after changing a user's permissions, and in tests). */
    public static function flush(?int $userId = null): void
    {
        if ($userId === null) {
            self::$memo = [];

            return;
        }

        unset(self::$memo[$userId]);
    }
}
