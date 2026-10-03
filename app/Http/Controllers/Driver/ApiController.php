<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\DriverLoginRequest;
use App\Models\Driver;
use App\Services\Driver\DriverSnapshot;
use App\Sync\SyncProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver\ApiController ( /driver/api/* )
//  Location: app/Http/Controllers/Driver/ApiController.php
//
//  What the Driver App on the phone talks to (JSON):
//    POST login   → mobile + PIN (App\Http\Requests\Driver\DriverLoginRequest)
//    GET  me      → the driver's profile + app settings; the phone
//                   keeps a copy to work offline. Also refreshes the
//                   security cookie before an upload.
//    GET  snapshot → the driver's open trips, wallets and lists (DriverSnapshot)
//    POST uploads → one photo (UploadController)
//    POST sync    → a batch of offline entries (App\Sync\SyncProcessor)
//    POST logout  → sign out
//
//  Never returns prices, revenue or profit (Scope §8.1).
// ══════════════════════════════════════════════════════════════════

class ApiController extends Controller
{
    public function login(DriverLoginRequest $request): JsonResponse
    {
        $driver = $request->authenticate();
        $request->session()->regenerate();

        return response()->json($this->profile($driver));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user('driver')));
    }

    public function snapshot(Request $request, DriverSnapshot $snapshot): JsonResponse
    {
        return response()->json($snapshot->build($request->user('driver')));
    }

    public function sync(Request $request, SyncProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'items'               => ['required', 'array', 'min:1', 'max:'.(int) config('eltara.sync.max_batch', 50)],
            'items.*.uuid'        => ['required', 'uuid', 'distinct'],
            'items.*.type'        => ['required', 'string', 'max:60'],
            'items.*.payload'     => ['nullable', 'array'],
            'items.*.recorded_at' => ['nullable', 'date'],
        ]);

        return response()->json([
            'results'     => $processor->process($request->user('driver'), $data['items']),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('driver')->logout();

        if (! Auth::guard('web')->check() && ! Auth::guard('client')->check()) {
            $request->session()->invalidate();
        }
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    private function profile(Driver $driver): array
    {
        $driver->loadMissing('company');

        return [
            'driver' => [
                'id'                 => $driver->id,
                'name'               => $driver->name,
                'initials'           => $driver->initials(),
                'mobile'             => $driver->mobile,
                'language'           => $driver->preferredLanguage(),
                'theme'              => $driver->preferredTheme(),
                'license_expires_at' => $driver->license_expires_at?->toDateString(),
            ],
            'company' => [
                'name_ar'   => $driver->company->name_ar,
                'name_en'   => $driver->company->name_en,
                'read_only' => $driver->company->isReadOnly(),
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }
}
