<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

// ══════════════════════════════════════════════════════════════════
//  El Tara — AppNotification (one line in the bell)
//  Location: app/Notifications/AppNotification.php
//
//  Stored in the `notifications` table only (no e-mail): the bell in
//  the top bar of the office and client portals shows it. It keeps a
//  KEY and its numbers, not a finished sentence, so each reader sees
//  it in his own language (lang key  notif.<key>  on the screen):
//      key    client_request.new
//      params {number: "R-00007", customer: "…"}
//      url    where a click goes (a relative path)
// ══════════════════════════════════════════════════════════════════

class AppNotification extends Notification
{
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        public readonly ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['key' => $this->key, 'params' => $this->params, 'url' => $this->url];
    }
}
