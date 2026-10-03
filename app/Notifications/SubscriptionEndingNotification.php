<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SubscriptionEndingNotification
//  Location: app/Notifications/SubscriptionEndingNotification.php
//
//  Emailed to a company's admin when its subscription ends within
//  30 days (Scope §4.2; config/eltara.php → subscription). Sent by
//  the daily command subscriptions:notify-expiring. Written in the
//  admin's own language. It explains that after the end date the
//  company becomes READ-ONLY — nothing is deleted.
//  Views: emails/subscription-ending + text twin.
// ══════════════════════════════════════════════════════════════════

class SubscriptionEndingNotification extends Notification
{
    public function __construct(
        private readonly Company $company,
        private readonly int $daysLeft,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->language ?? app()->getLocale();

        return (new MailMessage)
            ->subject(trans_choice('emails.subscription_ending.subject', $this->daysLeft, ['days' => $this->daysLeft], $locale))
            ->view(['emails.subscription-ending', 'emails.text.subscription-ending'], [
                'user'        => $notifiable,
                'companyName' => $this->company->displayName($locale),
                'daysLeft'    => $this->daysLeft,
                'endsOn'      => $this->company->subscription_ends_at?->toDateString(),
                'locale'      => $locale,
            ]);
    }
}
