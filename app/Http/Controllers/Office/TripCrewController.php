<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\Trip;
use App\Models\TripCrew;
use App\Services\Trips\CrewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\TripCrewController (second driver, assistant)
//  Location: app/Http/Controllers/Office/TripCrewController.php
//
//  A trip has one main driver who holds the money. Here the office records
//  a second driver or an assistant and the allowance he gets; the rules
//  (and the allowance expense) are in App\Services\Trips\CrewService.
//  Permission: trips.edit (an allowance also needs trip_expenses.create).
// ══════════════════════════════════════════════════════════════════

class TripCrewController extends Controller
{
    use RunsTripRules;

    public function store(Request $request, Trip $trip, CrewService $service): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(fn () => $service->add($trip, $data, $request->user('web')), __('crew.ok.saved'));
    }

    public function update(Request $request, Trip $trip, TripCrew $crew, CrewService $service): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(fn () => $service->update($crew, $data, $request->user('web')), __('crew.ok.saved'));
    }

    public function destroy(Request $request, Trip $trip, TripCrew $crew, CrewService $service): RedirectResponse
    {
        return $this->attempt(fn () => $service->remove($crew, $request->user('web')), __('crew.ok.deleted'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'role' => ['required', Rule::in(TripCrew::ROLES)],
            'name' => ['required', 'string', 'max:80'],
            'allowance' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);
    }
}
