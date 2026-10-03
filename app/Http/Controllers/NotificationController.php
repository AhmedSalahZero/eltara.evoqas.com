<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  El Tara — NotificationController (the bell)
//  Location: app/Http/Controllers/NotificationController.php
//
//  Scope §11 in-app notifications, for the office and the client
//  portal (the signed-in person only sees his own):
//    index    → the full list (Step 7): every line, read or not, newest first
//    read     → one line read (and the screen goes to its link)
//    readAll  → all lines read
//  The list itself is a shared page prop (HandleInertiaRequests).
// ══════════════════════════════════════════════════════════════════

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $unreadOnly = $request->query('filter') === 'unread';
        $query = $unreadOnly ? $this->who($request)->unreadNotifications() : $this->who($request)->notifications();

        return Inertia::render('Notifications/Index', [
            'filter' => $unreadOnly ? 'unread' : 'all',
            'unread' => $this->who($request)->unreadNotifications()->count(),
            'items'  => $query->latest()->paginate(30)->withQueryString()->through(fn ($n) => [
                'id' => $n->id, 'key' => $n->data['key'] ?? '', 'params' => $n->data['params'] ?? [], 'url' => $n->data['url'] ?? null,
                'read' => $n->read_at !== null, 'at' => $n->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->who($request)->notifications()->whereKey($id)->first()?->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->who($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function who(Request $request)
    {
        return $request->is('client', 'client/*') ? $request->user('client') : $request->user('web');
    }
}
