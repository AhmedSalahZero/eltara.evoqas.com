<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\CompanySetting;
use App\Models\Trip;
use App\Models\TripCharge;
use App\Services\Trips\Actor;
use App\Services\Trips\TripService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\TripActionController (moving a trip along)
//  Location: app/Http/Controllers/Office/TripActionController.php
//
//  The trip's "next step" button and its companions (Scope §6.3):
//    step     → accept (for the driver) · start loading · depart
//    custody  → hand custody to the driver (again = a top-up)
//    deliver  → delivered, with the proof-of-delivery photo
//    cancel   → only before any money has moved
//    policy   → change this trip's transfer policy and limit
//    charges  → extra charges and deductions (revenue side)
//
//  Every step records its time and who did it on the timeline. From
//  Step 4 the driver does most of them from his phone; the office
//  can always do them for him (e.g. he phoned in).
//
//  Permissions: trips.edit (steps, delivery, policy, charges),
//  trips.delete (cancel), wallet_transfers.create (custody — money
//  put into a driver's wallet).
// ══════════════════════════════════════════════════════════════════

class TripActionController extends Controller
{
    use RunsTripRules;

    public function step(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', Rule::in(['accept', 'loading', 'depart'])],
            'at'   => ['nullable', 'date'],
        ]);
        $step = $data['step'];
        $at = ! empty($data['at']) ? Carbon::parse($data['at']) : null;
        $actor = Actor::user($request->user('web'));

        $response = $this->attempt(fn () => match ($step) {
            'accept'  => $service->accept($trip, $actor, null, null, $at),
            'loading' => $service->startLoading($trip, $actor, null, null, $at),
            'depart'  => $service->depart($trip, $actor, null, null, $at),
        }, __('trips.ok.step'));

        // Custody the office issued but the driver has not signed for: the trip can go on, but not silently.
        if (! session()->has('error') && in_array($step, ['loading', 'depart'], true) && ! $trip->is_hired && $trip->custody_issued_at) {
            $issued = (float) \App\Models\WalletEntry::query()->where('trip_id', $trip->id)->where('wallet', 'custody')->where('type', 'custody_issued')->sum('amount');
            $signed = (float) \App\Models\TripEvent::query()->where('trip_id', $trip->id)->where('type', 'custody_received')->get()->sum(fn ($e) => (float) ($e->meta['amount'] ?? 0));
            if ($issued - $signed > 0.01) {
                $response->with('warning', __('trips.custody_unsigned_warn'));
            }
        }

        // Not a block (a running trip is left alone), but never silent.
        $vehicle = $trip->vehicle()->first();
        if (! session()->has('error') && $vehicle && $vehicle->status === 'maintenance') {
            $response->with('warning', __('trips.truck_in_maintenance_warn', ['plate' => $vehicle->plateText()]));
        }

        return $response;
    }

    public function custody(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'note'   => ['nullable', 'string', 'max:250'],
        ]);

        return $this->attempt(
            fn () => $service->issueCustody($trip, (float) $data['amount'], Actor::user($request->user('web')), $data['note'] ?? null),
            __('trips.ok.custody', ['amount' => number_format((float) $data['amount'])]),
        );
    }

    public function deliver(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate([
            'pod'      => ['required', 'image', 'max:8192'],
            'receiver' => ['nullable', 'string', 'max:120'],
            'at'       => ['nullable', 'date'],
        ]);
        $at = ! empty($data['at']) ? Carbon::parse($data['at']) : null;

        return $this->attempt(
            fn () => $service->deliver($trip, $request->file('pod'), $data['receiver'] ?? null, Actor::user($request->user('web')), null, null, $at),
            __('trips.ok.delivered'),
        );
    }

    public function cancel(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);

        return $this->attempt(fn () => $service->cancel($trip, $data['reason'], $request->user('web')), __('trips.ok.cancelled'));
    }

    public function policy(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate([
            'transfer_policy'     => ['required', Rule::in(CompanySetting::POLICIES)],
            'auto_transfer_limit' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        return $this->attempt(fn () => $service->changePolicy(
            $trip, $data['transfer_policy'], isset($data['auto_transfer_limit']) ? (float) $data['auto_transfer_limit'] : null, $request->user('web'),
        ), __('trips.ok.saved'));
    }

    public function storeCharge(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate([
            'kind'   => ['required', Rule::in(TripCharge::KINDS)],
            'label'  => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
        ]);

        return $this->attempt(fn () => $service->addCharge($trip, $data['kind'], $data['label'], (float) $data['amount'], $request->user('web')), __('trips.ok.saved'));
    }

    public function destroyCharge(Request $request, Trip $trip, TripCharge $charge, TripService $service): RedirectResponse
    {
        return $this->attempt(fn () => $service->removeCharge($charge, $request->user('web')), __('trips.ok.saved'));
    }
}
