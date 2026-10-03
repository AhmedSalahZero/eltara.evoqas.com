<?php

namespace App\Services;

use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Support\Facades\Password;

// ══════════════════════════════════════════════════════════════════
//  El Tara — AccountInvitation
//  Location: app/Services/AccountInvitation.php
//
//  Sends (or re-sends) the activation email for an office account.
//  Makes a fresh one-time link with the 'invites' broker (valid 7
//  days, config/auth.php). Sending a new link cancels the old one.
//  sendToClient() does the same for client-portal users.
// ══════════════════════════════════════════════════════════════════

final class AccountInvitation
{
    public function send(User $user): void
    {
        $token = Password::broker('invites')->createToken($user);

        $user->sendActivationNotification($token);
    }

    /** The same for a client-portal user (Step 2, 'client_invites' broker). */
    public function sendToClient(ClientUser $client): void
    {
        $token = Password::broker('client_invites')->createToken($client);

        $client->sendActivationNotification($token);
    }
}
