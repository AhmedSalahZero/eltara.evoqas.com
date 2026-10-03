<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ActivateAccountNotification
//  Location: app/Notifications/ActivateAccountNotification.php
//
//  The welcome email sent when an office account is created — by the
//  Super Admin (a new company's admin, Scope §4.1) or by a company
//  admin (a new office user, Scope §5 "invite by email").
//
//  The link opens /activate/{token}, where the person chooses their
//  own password. Nobody — not even the admin who created the account
//  — ever knows it. The link works for 7 days ('invites' broker in
//  config/auth.php); the admin can send a fresh one at any time.
//  Views: emails/activate-account + text twin.
// ══════════════════════════════════════════════════════════════════

class ActivateAccountNotification extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $broker = 'invites',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->language ?? app()->getLocale();
        $notifiable->loadMissing('company');

        $query = ['token' => $this->token, 'email' => $notifiable->email];
        if ($this->broker === 'client_invites') {
            $query['for'] = 'client';
        }

        $url = rtrim((string) config('app.url'), '/').route('activation.show', $query, false);

        return (new MailMessage)
            ->subject(__('emails.activate.subject', [], $locale))
            ->view(['emails.activate-account', 'emails.text.activate-account'], [
                'user'        => $notifiable,
                'companyName' => $notifiable->company?->displayName($locale) ?? config('app.name'),
                'url'         => $url,
                'expireDays'  => (int) round(config("auth.passwords.{$this->broker}.expire") / 1440),
                'locale'      => $locale,
            ]);
    }
}
