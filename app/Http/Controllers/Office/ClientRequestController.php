<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\ClientComplaint;
use App\Models\ClientRequest;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\TripRating;
use App\Models\Vehicle;
use App\Services\ClientRequestService;
use App\Services\Notifier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\ClientRequestController ( /office/client-requests )
//  Location: app/Http/Controllers/Office/ClientRequestController.php
//
//  Scope §6.2 "Client requests": three tabs
//    New                what clients asked for and nobody answered yet
//    Answered           assigned (trips made) / declined / withdrawn
//    Complaints & ratings  complaints to answer and the stars given
//  Actions: approve now, assign trucks now or later (one planned trip
//  per truck asked — the client is told), decline with a reason, reply to a complaint.
//  Permissions: client_requests.view (see) · .approve (assign /
//  decline) · .edit (reply to complaints).
// ══════════════════════════════════════════════════════════════════

class ClientRequestController extends Controller
{
    use RunsTripRules;

    public function index(Request $request): Response
    {
        $tab = in_array($request->query('tab'), ['approved', 'answered', 'feedback'], true) ? $request->query('tab') : 'new';
        $user = $request->user('web');

        $tiles = [
            'new'       => ClientRequest::query()->where('status', 'new')->count(),
            'approved'  => ClientRequest::query()->where('status', 'approved')->count(),
            'complaints' => ClientComplaint::query()->where('status', 'open')->count(),
        ];

        $props = ['tab' => $tab, 'tiles' => $tiles, 'requests' => [], 'complaints' => [], 'ratings' => [], 'options' => null];

        if ($tab !== 'feedback') {
            $requests = ClientRequest::query()->whereIn('status', match ($tab) { 'new' => ['new'], 'approved' => ['approved'], default => ['assigned', 'declined', 'cancelled'] })
                ->with(['customer:id,name_ar,name_en', 'lines.route', 'cargoType', 'requester:id,name', 'trips' => fn ($q) => $q->with(['vehicle:id,plate_number,plate_letters', 'driver:id,name'])->orderBy('id')])
                ->orderBy(in_array($tab, ['new', 'approved'], true) ? 'loading_at' : 'decided_at', in_array($tab, ['new', 'approved'], true) ? 'asc' : 'desc')->limit(100)->get();

            $props['requests'] = $requests->map(fn (ClientRequest $r) => [
                'id'         => $r->id,
                'number'     => $r->number,
                'status'     => $r->status,
                'customer'   => $r->customer?->displayName(),
                'by'         => $r->requester?->name,
                'lines'      => $r->lines->map(fn ($l) => ['id' => $l->id, 'route' => $l->route?->displayName(), 'trucks' => $l->trucks_count, 'unit_price' => $l->unit_price, 'subtotal' => $l->subtotal()])->values(),
                // one entry per truck asked, in order: what the assign form shows for each truck
                'slots'      => collect($r->slots())->map(fn ($l) => $l->route?->displayName())->values(),
                'loading_at' => $r->loading_at?->toIso8601String(),
                'trucks'     => $r->trucks_count,
                'cargo'      => $r->cargoType?->displayName(),
                'notes'      => $r->notes,
                'total'      => $r->expectedTotal(),
                'decline_reason' => $r->decline_reason,
                'created_at' => $r->created_at?->toIso8601String(),
                // Approved but still without trucks and loading within 3 days
                'urgent'     => $r->status === 'approved' && $r->loading_at !== null && $r->loading_at->lte(now()->addDays(3)),
                'trips'      => $r->trips->map(fn (Trip $t) => [
                    'id' => $t->id, 'number' => $t->number,
                    'vehicle' => $t->vehicle ? ['number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters] : null, 'driver' => $t->driver?->name,
                ])->values(),
            ])->values();

            if (in_array($tab, ['new', 'approved'], true) && $user->can('client_requests.approve')) {
                $props['options'] = $this->assignOptions();
            }
        } else {
            $props['complaints'] = ClientComplaint::query()->with(['customer:id,name_ar,name_en', 'author:id,name', 'trip:id,number'])
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->latest('id')->limit(100)->get()
                ->map(fn (ClientComplaint $c) => [
                    'id' => $c->id, 'customer' => $c->customer?->displayName(), 'by' => $c->author?->name, 'subject' => $c->subject, 'body' => $c->body,
                    'status' => $c->status, 'reply' => $c->reply, 'replied_at' => $c->replied_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
                    'trip' => $c->trip ? ['id' => $c->trip->id, 'number' => $c->trip->number] : null,
                ])->values();

            $props['ratings'] = TripRating::query()->with(['customer:id,name_ar,name_en', 'trip:id,number'])->latest('id')->limit(100)->get()
                ->map(fn (TripRating $r) => [
                    'id' => $r->id, 'stars' => $r->stars, 'comment' => $r->comment, 'customer' => $r->customer?->displayName(),
                    'at' => $r->created_at?->toIso8601String(), 'trip' => ['id' => $r->trip->id, 'number' => $r->trip->number],
                ])->values();
            $props['average'] = ($avg = TripRating::query()->avg('stars')) !== null ? round((float) $avg, 1) : null;
        }

        return Inertia::render('Office/ClientRequests/Index', $props);
    }

    public function assign(Request $request, int $clientRequest, ClientRequestService $service): RedirectResponse
    {
        $model = ClientRequest::query()->findOrFail($clientRequest);
        $companyId = $request->user('web')->company_id;

        $data = $request->validate([
            'assignments'              => ['required', 'array', 'min:1', 'max:50'],
            'assignments.*.vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('company_id', $companyId)],
            'assignments.*.driver_id'  => ['nullable', 'integer', Rule::exists('drivers', 'id')->where('company_id', $companyId)],
        ]);

        return $this->attempt(function () use ($service, $model, $data, $request) {
            $trips = $service->assign($model, $data['assignments'], $request->user('web'));

            return back()->with('success', __('client.ok.assigned', ['count' => count($trips)]));
        });
    }

    public function approve(Request $request, int $clientRequest, ClientRequestService $service): RedirectResponse
    {
        $model = ClientRequest::query()->findOrFail($clientRequest);

        return $this->attempt(fn () => $service->approve($model, $request->user('web')), __('client.ok.approved'));
    }

    public function decline(Request $request, int $clientRequest, ClientRequestService $service): RedirectResponse
    {
        $model = ClientRequest::query()->findOrFail($clientRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);

        return $this->attempt(fn () => $service->decline($model, $data['reason'], $request->user('web')), __('client.ok.declined'));
    }

    public function reply(Request $request, int $complaint, Notifier $notifier): RedirectResponse
    {
        $model = ClientComplaint::query()->with('customer')->findOrFail($complaint);
        $data = $request->validate(['reply' => ['required', 'string', 'max:2000']]);

        $model->forceFill(['status' => 'answered', 'reply' => $data['reply'], 'replied_by' => $request->user('web')->id, 'replied_at' => now()])->save();
        Audit::record('complaint.answered', $model);

        $notifier->toClients($model->customer_id, 'complaint.answered', ['subject' => $model->subject],
            route('client.feedback.index', [], false), $model->client_user_id);

        return back()->with('success', __('client.ok.replied'));
    }

    /** Trucks and drivers for the assign form, with what is already booked. */
    private function assignOptions(): array
    {
        $planned = Trip::query()->where('status', 'planned')->get(['id', 'number', 'vehicle_id', 'driver_id']);
        $running = Trip::query()->inProgress()->get(['id', 'number', 'vehicle_id', 'driver_id']);
        $state = fn (string $key, int $id) => ['booked' => $planned->firstWhere($key, $id)?->number, 'on_trip' => $running->firstWhere($key, $id)?->number];

        return [
            'vehicles' => Vehicle::query()->with(['driver:id,name', 'vehicleType'])->orderBy('ownership')->orderBy('plate_number')->get()
                ->map(fn (Vehicle $v) => [
                    'id' => $v->id, 'number' => $v->plate_number, 'letters' => $v->plate_letters, 'type' => $v->vehicleType?->displayName(),
                    'hired' => $v->isHired(), 'driver_id' => $v->driver_id, 'driver' => $v->driver?->name, 'maintenance' => $v->status === 'maintenance',
                    ...$state('vehicle_id', $v->id),
                ])->values(),
            'drivers'  => Driver::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Driver $d) => ['id' => $d->id, 'name' => $d->name, ...$state('driver_id', $d->id)])->values(),
        ];
    }
}
