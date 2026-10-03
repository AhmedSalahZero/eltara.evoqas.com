<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Services\AccountInvitation;
use App\Support\Audit;
use App\Support\EgyptPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\TeamController ( /client/team )
//  Location: app/Http/Controllers/Client/TeamController.php
//  Scope §7 "My company users": the account admin adds colleagues
//  (they get an activation e-mail) and can suspend / re-activate
//  them; other users can request and track but not manage users.
//  Everyone can see the list.
// ══════════════════════════════════════════════════════════════════

class TeamController extends Controller
{
    use ServesClient;

    public function index(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('Client/Team', [
            'users'    => ClientUser::query()->where('customer_id', $client->customer_id)->orderByDesc('is_account_admin')->orderBy('name')->get()
                ->map(fn (ClientUser $u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'job_title' => $u->job_title,
                    'is_account_admin' => $u->is_account_admin, 'is_active' => $u->is_active, 'me' => $u->id === $client->id,
                    'last_login_at' => $u->last_login_at?->toIso8601String(), 'activated' => $u->email_verified_at !== null,
                ])->values(),
            'canManage' => (bool) $client->is_account_admin,
        ]);
    }

    public function store(Request $request, AccountInvitation $invitation): RedirectResponse
    {
        $client = $this->client($request);
        abort_unless($client->is_account_admin, 403);

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => $request->filled('phone') ? EgyptPhone::normalize($request->input('phone')) : null,
        ]);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:150', 'unique:client_users,email', 'unique:users,email'],
            'phone'     => ['nullable', 'regex:'.EgyptPhone::PATTERN, 'unique:client_users,phone', 'unique:users,phone'],
            'job_title' => ['nullable', 'string', 'max:100'],
        ], [
            'email.unique' => __('errors.email_taken'),
            'phone.unique' => __('errors.phone_taken'),
        ]);

        $new = DB::transaction(function () use ($data, $client) {
            $new = ClientUser::query()->create($data + [
                'company_id' => $client->company_id, 'customer_id' => $client->customer_id, 'password' => Str::password(32),
                'is_account_admin' => false, 'language' => $client->preferredLanguage(), 'is_active' => true,
            ]);
            Audit::record('client_user.invited', $new, ['after' => ['email' => $new->email, 'by' => $client->email]]);

            return $new;
        });

        $invitation->sendToClient($new);

        return back()->with('success', __('common.user_invited', ['email' => $new->email]));
    }

    public function toggle(Request $request, int $user): RedirectResponse
    {
        $client = $this->client($request);
        abort_unless($client->is_account_admin, 403);

        $target = ClientUser::query()->where('customer_id', $client->customer_id)->findOrFail($user);
        if ($target->id === $client->id || $target->is_account_admin) {
            return back()->with('error', __('client.cannot_suspend_admin'));
        }

        $target->forceFill(['is_active' => ! $target->is_active])->save();
        Audit::record($target->is_active ? 'client_user.reactivated' : 'client_user.suspended', $target);

        return back()->with('success', __($target->is_active ? 'common.user_reactivated' : 'common.user_suspended', ['name' => $target->name]));
    }
}
