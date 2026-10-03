<?php

namespace App\Http\Controllers\Office;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Office\StoreUserRequest;
use App\Http\Requests\Office\UpdatePermissionsRequest;
use App\Http\Requests\Office\UpdateUserRequest;
use App\Models\User;
use App\Services\AccountInvitation;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\UserController ( /office/users )
//  Location: app/Http/Controllers/Office/UserController.php
//
//  "Users & permissions" (Scope §5), inside ONE company:
//    index        → users list (last sign-in, suspended, activated),
//                   the two limit meters, and the permission grid
//    store        → invite by email (inside the office-users limit),
//                   optionally copying another user's permissions
//    update       → name, job title, email, mobile
//    permissions  → save the grid + the approval limit (audited)
//    toggle       → suspend / reactivate (audited; reactivating
//                   needs a free place under the limit)
//    sendLink     → not activated yet → a new activation email;
//                   activated → a password reset email
//
//  Rules that never bend:
//    · the company admin always has every permission and cannot be
//      limited or suspended here;
//    · nobody can suspend themselves;
//    · everything is looked up through $company->users(), so an id
//      from another company simply is not found (404).
// ══════════════════════════════════════════════════════════════════

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $company = $request->user('web')->company;

        $users = $company->users()
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [UserRole::CompanyAdmin->value])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return Inertia::render('Office/Users/Index', [
            'users'  => $users->map(fn (User $u) => [
                'id'             => $u->id,
                'name'           => $u->name,
                'initials'       => $u->initials(),
                'email'          => $u->email,
                'phone'          => $u->phone,
                'job_title'      => $u->job_title,
                'is_admin'       => $u->isCompanyAdmin(),
                'is_active'      => $u->is_active,
                'activated'      => $u->isActivated(),
                'last_login_at'  => $u->last_login_at?->toIso8601String(),
                'permissions'    => $u->permissionKeys(),
                'approval_limit' => $u->approval_limit,
            ])->values(),
            'matrix' => Permissions::matrix(),
            'needsApprovalLimit' => config('permissions.needs_approval_limit'),
            'limits' => [
                'office_used'   => $company->officeSeatsUsed(),
                'office_limit'  => $company->office_users_limit,
                'drivers_used'  => $company->driverSeatsUsed(),
                'drivers_limit' => $company->driver_accounts_limit,
            ],
        ]);
    }

    public function store(StoreUserRequest $request, AccountInvitation $invitation): RedirectResponse
    {
        $actor = $request->user('web');
        $company = $actor->company;

        if (! $company->hasFreeOfficeSeat()) {
            return back()->with('error', __('errors.office_limit_reached', ['limit' => $company->office_users_limit]));
        }

        $permissions = [];
        $approvalLimit = null;
        if ($request->filled('copy_from_user_id')) {
            $source = $company->users()->findOrFail($request->integer('copy_from_user_id'));
            $permissions = $source->permissionKeys();
            $approvalLimit = $source->approval_limit;

            if (! $actor->isCompanyAdmin() && $this->exceedsOwnPowers($actor, $permissions, $approvalLimit)) {
                return back()->with('error', __('errors.cannot_grant_unheld'));
            }
        }

        $user = DB::transaction(function () use ($request, $company, $actor, $permissions, $approvalLimit) {
            $user = $company->users()->create([
                'name'           => $request->input('name'),
                'email'          => $request->input('email'),
                'phone'          => $request->input('phone'),
                'job_title'      => $request->input('job_title'),
                'language'       => $request->input('language'),
                'password'       => Str::password(32),
                'role'           => UserRole::OfficeUser->value,
                'permissions'    => $permissions,
                'approval_limit' => $approvalLimit,
                'is_active'      => true,
                'created_by'     => $actor->id,
            ]);

            Audit::record('user.invited', $user, ['after' => [
                'email' => $user->email, 'permissions' => $permissions, 'copied_from' => $request->input('copy_from_user_id'),
            ]]);

            return $user;
        });

        $invitation->send($user);

        return back()->with('success', __('common.user_invited', ['email' => $user->email]));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user = $this->find($request, $user);
        $this->protectAdmin($request, $user);

        $user->fill($request->validated());
        if ($user->isDirty('email')) {
            Audit::record('user.email_changed', $user, ['before' => $user->getOriginal('email'), 'after' => $user->email]);
        }
        $user->save();

        return back()->with('success', __('common.user_updated'));
    }

    /** Would these keys (or this approval limit) give someone more than the person granting them holds? */
    private function exceedsOwnPowers(User $actor, array $keys, mixed $approvalLimit, ?User $target = null): bool
    {
        // Keeping what the person already has is fine; only NEW powers must be held by the giver.
        $added = array_diff($keys, $target?->permissionKeys() ?? []);
        $held = $actor->permissionKeys();
        foreach ($added as $key) {
            if (in_array($key, $held, true)) {
                continue;
            }
            // "View" is added automatically next to any other action of its feature,
            // so it is fine when the giver holds some action of that feature.
            $feature = strtok($key, '.');
            if (str_ends_with($key, '.view') && array_filter($held, fn ($h) => str_starts_with($h, $feature.'.'))) {
                continue;
            }

            return true;
        }

        $raised = $approvalLimit !== null && $approvalLimit !== ''
            && (float) $approvalLimit > (float) ($target?->approval_limit ?? 0) + 0.001;

        return in_array('wallet_transfers.approve', $keys, true) && $raised
            && (float) $approvalLimit > (float) ($actor->approval_limit ?? 0) + 0.001;
    }

    public function permissions(UpdatePermissionsRequest $request, User $user): RedirectResponse
    {
        $user = $this->find($request, $user);

        if ($user->isCompanyAdmin()) {
            return back()->with('error', __('errors.cannot_change_admin'));
        }

        $actor = $request->user('web');
        $new = $request->cleanPermissions();

        // Only the company admin may change their own or hand out powers they do not hold.
        if (! $actor->isCompanyAdmin()) {
            if ($user->is($actor)) {
                return back()->with('error', __('errors.cannot_edit_own_permissions'));
            }
            if ($this->exceedsOwnPowers($actor, $new, $request->input('approval_limit'), $user)) {
                return back()->with('error', __('errors.cannot_grant_unheld'));
            }
        }

        DB::transaction(function () use ($request, $user) {
            $before = ['permissions' => $user->permissionKeys(), 'approval_limit' => $user->approval_limit];

            $user->forceFill([
                'permissions'    => $request->cleanPermissions(),
                'approval_limit' => $request->filled('approval_limit') ? $request->input('approval_limit') : null,
            ])->save();

            Permissions::flush($user->id);

            Audit::record('permissions.changed', $user, [
                'before' => $before,
                'after'  => ['permissions' => $user->permissionKeys(), 'approval_limit' => $user->approval_limit],
            ]);
        });

        return back()->with('success', __('common.permissions_saved', ['name' => $user->name]));
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        $user = $this->find($request, $user);
        $actor = $request->user('web');

        if ($user->is($actor)) {
            return back()->with('error', __('errors.cannot_suspend_self'));
        }

        if ($user->isCompanyAdmin()) {
            return back()->with('error', __('errors.cannot_change_admin'));
        }

        $company = $actor->company;
        if (! $user->is_active && ! $company->hasFreeOfficeSeat()) {
            return back()->with('error', __('errors.office_limit_reached', ['limit' => $company->office_users_limit]));
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['is_active' => ! $user->is_active])->save();
            Permissions::flush($user->id);
            Audit::record($user->is_active ? 'user.reactivated' : 'user.suspended', $user);
        });

        return back()->with('success', __($user->is_active ? 'common.user_reactivated' : 'common.user_suspended', ['name' => $user->name]));
    }

    public function sendLink(Request $request, User $user, AccountInvitation $invitation): RedirectResponse
    {
        $user = $this->find($request, $user);

        if (! $user->isActivated()) {
            $invitation->send($user);

            return back()->with('success', __('common.activation_resent', ['email' => $user->email]));
        }

        Password::broker('users')->sendResetLink(['email' => $user->email]);

        return back()->with('success', __('common.reset_link_sent_to', ['email' => $user->email]));
    }

    /** The user, only if they belong to the signed-in person's company. */
    private function find(Request $request, User $user): User
    {
        abort_unless($user->company_id === $request->user('web')->company_id, 404);

        return $user;
    }

    /** Only the company admin may edit the company admin's own details. */
    private function protectAdmin(Request $request, User $user): void
    {
        abort_if($user->isCompanyAdmin() && ! $user->is($request->user('web')), 403, __('errors.cannot_change_admin'));
    }
}
