<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ResetPasswordNotification
//  Location: app/Notifications/ResetPasswordNotification.php
//
//  The "reset your password" email, in the person's own language.
//  Used for office staff (broker 'users') and client-portal users
//  (broker 'clients' — the link then carries ?for=client so the
//  reset screen uses the right accounts).
//
//  Sent at once, not queued: the person is waiting for it.
//  The link is built on APP_URL, never on the address the request
//  came in on (a forged Host header could otherwise steal the token).
//  Views: emails/reset-password + text twin.
// ══════════════════════════════════════════════════════════════════

class ResetPasswordNotification extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $broker = 'users',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->language ?? app()->getLocale();

        $query = ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()];
        if ($this->broker === 'clients') {
            $query['for'] = 'client';
        }

        $url = rtrim((string) config('app.url'), '/').route('password.reset', $query, false);

        return (new MailMessage)
            ->subject(__('emails.reset_password.subject', [], $locale))
            ->view(['emails.reset-password', 'emails.text.reset-password'], [
                'user'          => $notifiable,
                'url'           => $url,
                'expireMinutes' => (int) config('auth.passwords.'.$this->broker.'.expire'),
                'locale'        => $locale,
            ]);
    }
}
