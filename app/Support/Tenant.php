<?php

namespace App\Support;

use App\Models\ClientUser;
use App\Models\Driver;
use App\Models\User;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Tenant (which company is this request working for?)
//  Location: app/Support/Tenant.php
//
//  One answer used everywhere: the id of the company whose data the
//  current request may touch. App\Support\Concerns\BelongsToCompany
//  reads it to filter every query, so an office user, a driver or a
//  client user can only ever see their own company's rows.
//
//  How the answer is found:
//    1. set() — the portal middleware (office / driver / client)
//       sets it explicitly from the account that portal signed in.
//       This is the normal path for every web request.
//    2. Otherwise, the first signed-in account found on the
//       office, driver or client guard.
//    3. Nobody signed in (console commands, queued jobs, tests) →
//       null, meaning "not scoped". Code running there must pass
//       company ids explicitly — see forCompany().
//
//  A signed-in account that belongs to no company (other than the
//  Super Admin) yields ORPHAN, which matches no rows: "no company"
//  must mean "nothing", never "everything".
// ══════════════════════════════════════════════════════════════════

final class Tenant
{
    /** Matches no company — used for accounts whose company is missing. */
    public const ORPHAN = -1;

    private static ?int $companyId = null;

    private static bool $explicit = false;

    public static function set(?int $companyId): void
    {
        self::$companyId = $companyId;
        self::$explicit = true;
    }

    public static function clear(): void
    {
        self::$companyId = null;
        self::$explicit = false;
    }

    /** The current company id, ORPHAN, or null (= not scoped). */
    public static function id(): ?int
    {
        if (self::$explicit) {
            return self::$companyId;
        }

        foreach (['web', 'driver', 'client'] as $guard) {
            $account = auth()->guard($guard)->user();

            if (! $account) {
                continue;
            }

            if ($account instanceof User && $account->isSuperAdmin()) {
                return null;
            }

            /** @var User|Driver|ClientUser $account */
            return $account->company_id ?: self::ORPHAN;
        }

        return null;
    }

    /** Run $callback as if working for $companyId (jobs, commands, seeders). */
    public static function forCompany(int $companyId, callable $callback): mixed
    {
        $previous = [self::$companyId, self::$explicit];
        self::set($companyId);

        try {
            return $callback();
        } finally {
            [self::$companyId, self::$explicit] = $previous;
        }
    }
}
