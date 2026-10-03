<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\ClientUser;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Audit (write to the audit log)
//  Location: app/Support/Audit.php
//
//  One line to record a sensitive action (Scope §12):
//
//      Audit::record('permissions.changed', $user, [
//          'before' => $old, 'after' => $new,
//      ]);
//
//  Who did it is taken from whoever is signed in (office user, driver
//  or client user); console commands and jobs are recorded as
//  "system". The company is taken from the subject (or the actor).
//
//  Action names are "area.verb" in English, e.g. company.created,
//  company.limits_changed, user.suspended, permissions.changed,
//  month.closed, transfer.approved. The screens translate them.
// ══════════════════════════════════════════════════════════════════

final class Audit
{
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?int $companyId = null, ?Model $actor = null): AuditLog
    {
        // $actor is given when nobody is signed in yet (a sign-in attempt): the account that tried.
        [$type, $id, $name, $actorCompany] = $actor ? self::describe($actor) : self::actor();

        return AuditLog::query()->create([
            'company_id'   => $companyId ?? $subject?->getAttribute('company_id') ?? ($subject instanceof \App\Models\Company ? $subject->getKey() : null) ?? $actorCompany,
            'actor_type'   => $type,
            'actor_id'     => $id,
            'actor_name'   => $name,
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'changes'      => $changes ?: null,
            'ip'           => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
        ]);
    }

    /** @return array{0:string,1:?int,2:?string,3:?int} */
    private static function describe(Model $account): array
    {
        $type = match (true) {
            $account instanceof Driver     => 'driver',
            $account instanceof ClientUser => 'client',
            default                        => 'user',
        };

        return [$type, $account->getKey(), $account->getAttribute('name'), $account->getAttribute('company_id')];
    }

    /** @return array{0:string,1:?int,2:?string,3:?int} */
    private static function actor(): array
    {
        foreach (['web' => 'user', 'driver' => 'driver', 'client' => 'client'] as $guard => $type) {
            /** @var User|Driver|ClientUser|null $account */
            $account = auth()->guard($guard)->user();

            if ($account) {
                return [$type, $account->getKey(), $account->name, $account->company_id];
            }
        }

        return ['system', null, 'System', null];
    }
}
