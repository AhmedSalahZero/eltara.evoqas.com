<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  El Tara — PreferenceController (language + dark/light switch)
//  Location: app/Http/Controllers/PreferenceController.php
//
//  The ع / EN and sun / moon buttons in the top bar (Scope §2 —
//  "switchable per user"). Saved on the signed-in account (office or
//  client portal — `portal=client` says which), or in the session
//  for a guest on the sign-in page. Always allowed, even when the
//  company is read-only. (The Driver App saves its own choice through
//  the offline sync, so it works with no signal.)
// ══════════════════════════════════════════════════════════════════

class PreferenceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['sometimes', 'in:ar,en'],
            'theme'    => ['sometimes', 'in:dark,light'],
            'portal'   => ['nullable', 'in:client'],
        ]);

        $account = $data['portal'] ?? null ? $request->user('client') : ($request->user('web') ?? $request->user('client'));
        $changes = array_intersect_key($data, array_flip(['language', 'theme']));

        if ($account) {
            $account->forceFill($changes)->save();
        }

        if (isset($changes['language'])) {
            $request->session()->put('locale', $changes['language']);
        }

        return back();
    }
}
