<?php

namespace App\Http\Controllers\Office\Concerns;

use App\Services\Trips\TripRuleException;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — RunsTripRules (office controllers of Step 3)
//  Location: app/Http/Controllers/Office/Concerns/RunsTripRules.php
//
//  Runs one action of the trip services. If a business rule refuses
//  it (App\Services\Trips\TripRuleException — "a transfer is still
//  waiting for approval" …), the person is sent back to the same
//  screen with that message in red, instead of an error page.
//
//      return $this->attempt(fn () => $service->approve(...), __('trips.ok.approved'));
// ══════════════════════════════════════════════════════════════════

trait RunsTripRules
{
    protected function attempt(callable $action, ?string $success = null): RedirectResponse
    {
        try {
            $result = $action();
        } catch (TripRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return $success ? back()->with('success', $success) : back();
    }
}
