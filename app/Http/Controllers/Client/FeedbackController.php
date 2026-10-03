<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\ClientComplaint;
use App\Models\Trip;
use App\Models\TripRating;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\FeedbackController ( /client/feedback )
//  Location: app/Http/Controllers/Client/FeedbackController.php
//  Scope §7 "Ratings & complaints": his complaints with the replies,
//  the delivered trips still waiting for a rating, and his ratings.
//  store → a new complaint (about a trip or general); the office is
//  told. Rating itself is on the shipment page (ShipmentController).
// ══════════════════════════════════════════════════════════════════

class FeedbackController extends Controller
{
    use ServesClient;

    public function index(Request $request): Response
    {
        $customerId = $this->client($request)->customer_id;

        $complaints = ClientComplaint::query()->where('customer_id', $customerId)->with('trip:id,number')->latest('id')->limit(100)->get();
        $ratings = TripRating::query()->where('customer_id', $customerId)->with('trip:id,number,trip_route_id,delivered_at', 'trip.route')->latest('id')->limit(100)->get();
        $waiting = $this->myTrips($request)->whereIn('trips.status', ['delivered', 'settled'])->whereDoesntHave('rating')->with('route')
            ->orderByDesc('trips.delivered_at')->limit(50)->get();

        return Inertia::render('Client/Feedback', [
            'complaints' => $complaints->map(fn (ClientComplaint $c) => [
                'id' => $c->id, 'subject' => $c->subject, 'body' => $c->body, 'status' => $c->status, 'reply' => $c->reply,
                'replied_at' => $c->replied_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
                'trip' => $c->trip ? ['id' => $c->trip->id, 'number' => $c->trip->number] : null,
            ])->values(),
            'ratings'    => $ratings->map(fn (TripRating $r) => [
                'id' => $r->id, 'stars' => $r->stars, 'comment' => $r->comment, 'at' => $r->created_at?->toIso8601String(),
                'trip' => ['id' => $r->trip->id, 'number' => $r->trip->number, 'route' => $r->trip->route?->displayName()],
            ])->values(),
            'waiting'    => $waiting->map(fn (Trip $t) => [
                'id' => $t->id, 'number' => $t->number, 'route' => $t->route?->displayName(), 'delivered_at' => $t->delivered_at?->toIso8601String(),
            ])->values(),
            'trips'      => $this->myTrips($request)->orderByDesc('trips.loading_at')->limit(100)->get(['id', 'number'])
                ->map(fn (Trip $t) => ['id' => $t->id, 'number' => $t->number])->values(),
        ]);
    }

    public function store(Request $request, Notifier $notifier): RedirectResponse
    {
        $client = $this->client($request);
        $data = $request->validate([
            'trip_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'max:150'],
            'body'    => ['required', 'string', 'max:2000'],
        ]);

        $trip = ! empty($data['trip_id']) ? $this->ownTrip($request, $data['trip_id']) : null;

        $complaint = ClientComplaint::query()->create([
            'company_id' => $client->company_id, 'customer_id' => $client->customer_id, 'client_user_id' => $client->id,
            'trip_id' => $trip?->id, 'subject' => $data['subject'], 'body' => $data['body'], 'status' => 'open',
        ]);

        $notifier->toOffice($client->company_id, 'client_requests.view', 'complaint.new', [
            'customer' => $client->customer->displayName(), 'subject' => $complaint->subject,
        ], route('office.client-requests.index', ['tab' => 'feedback'], false));

        return back()->with('success', __('client.ok.complained'));
    }
}
