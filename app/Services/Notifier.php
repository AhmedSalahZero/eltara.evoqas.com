<?php

namespace App\Services;

use App\Models\ClientUser;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Collection;
use Throwable;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Notifier (who hears about what)
//  Location: app/Services/Notifier.php
//
//  Scope §11 — the in-app notifications of the two web portals:
//    toOffice($company, 'client_requests.view', …)  every active office
//        user of the company who holds that permission
//    toClients($customer, …)  every active user of that client
//        (or only $only — e.g. the author of a complaint)
//  It can never break the action that called it: a failure here is
//  only logged, the trip / money / request still goes through.
//  Reading them: App\Http\Controllers\NotificationController.
// ══════════════════════════════════════════════════════════════════

final class Notifier
{
    public function toOffice(int $companyId, string $permission, string $key, array $params = [], ?string $url = null): void
    {
        $this->run(function () use ($companyId, $permission, $key, $params, $url) {
            $users = User::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->can($permission));

            $this->send($users, $key, $params, $url);
        });
    }

    /** Like toOffice(), but only the users the $filter accepts (e.g. those whose approval limit covers the amount). */
    public function toOfficeWhere(int $companyId, string $permission, callable $filter, string $key, array $params = [], ?string $url = null): void
    {
        $this->run(function () use ($companyId, $permission, $filter, $key, $params, $url) {
            $users = User::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->can($permission) && $filter($u));

            $this->send($users, $key, $params, $url);
        });
    }

    public function toClients(int $customerId, string $key, array $params = [], ?string $url = null, ?int $onlyClientUserId = null): void
    {
        $this->run(function () use ($customerId, $key, $params, $url, $onlyClientUserId) {
            $users = ClientUser::query()->withoutGlobalScopes()->where('customer_id', $customerId)->where('is_active', true)
                ->when($onlyClientUserId, fn ($q) => $q->whereKey($onlyClientUserId))->get();

            $this->send($users, $key, $params, $url);
        });
    }

    private function send(Collection $users, string $key, array $params, ?string $url): void
    {
        foreach ($users as $user) {
            $user->notify(new AppNotification($key, $params, $url));
        }
    }

    private function run(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
