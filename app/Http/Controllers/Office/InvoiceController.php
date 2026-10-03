<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Trip;
use App\Services\Invoices\InvoiceService;
use App\Services\Trips\TripFigures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\InvoiceController ( /office/invoices )
//  Location: app/Http/Controllers/Office/InvoiceController.php
//
//  Invoice number linking (Scope §6.11). The invoice itself is issued
//  in the company's ERP; here the office only links its NUMBER to
//  trips — one invoice can cover several trips of one customer.
//    tiles   linked invoices, closed trips WITHOUT an invoice (count
//            and value), how long the oldest has waited
//    tabs    Linked invoices · Closed trips without an invoice
//    actions link trips to an invoice number · change an invoice
//            (number, date, add / remove trips) · remove the link
//  Permissions: invoice_links.view / create / edit / delete.
// ══════════════════════════════════════════════════════════════════

class InvoiceController extends Controller
{
    use RunsTripRules;

    public const TABS = ['invoices', 'open'];

    /** Most trips listed in the "without an invoice" tab / link form. */
    private const MAX_OPEN = 500;

    public function index(Request $request, InvoiceService $service): Response
    {
        $user = $request->user('web');
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'invoices';
        $search = trim((string) $request->query('search'));
        $customerId = $request->integer('customer') ?: null;

        $invoices = Invoice::query()->with('customer:id,name_ar,name_en')
            ->when($search !== '', fn ($q) => $q->where('number', 'like', "%{$search}%"))
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        // The trips of the invoices on this page, with their revenue — one query.
        $trips = TripFigures::withMoney(Trip::query()->select('trips.*')->whereIn('invoice_id', $invoices->getCollection()->pluck('id')->all() ?: [0]))
            ->with(['route:id,origin_ar,origin_en,destination_ar,destination_en'])
            ->orderBy('trips.settled_at')->get()->groupBy('invoice_id');

        $open = TripFigures::withMoney(Trip::query()->select('trips.*')->where('trips.status', 'settled')->whereNull('trips.invoice_id')
            ->when($customerId, fn ($q) => $q->where('trips.customer_id', $customerId))
            ->when($search !== '' && $tab === 'open', fn ($q) => $q->where('trips.number', 'like', "%{$search}%")))
            ->with(['customer:id,name_ar,name_en', 'route:id,origin_ar,origin_en,destination_ar,destination_en', 'vehicle:id,plate_number,plate_letters'])
            ->orderBy('trips.settled_at')->orderBy('trips.id')->limit(self::MAX_OPEN)->get();

        $now = Carbon::now();

        return Inertia::render('Office/Invoices/Index', [
            'tab'       => $tab,
            'filters'   => ['search' => $search, 'customer' => $customerId],
            'stats'     => $service->stats(),
            'invoices'  => [
                'data'  => $invoices->getCollection()->map(function (Invoice $i) use ($trips) {
                    $rows = $trips->get($i->id, collect());

                    return [
                        'id'        => $i->id,
                        'number'    => $i->number,
                        'issued_on' => $i->issued_on ? substr((string) $i->issued_on, 0, 10) : null,
                        'note'      => $i->note,
                        'customer'  => ['id' => $i->customer_id, 'name' => $i->customer?->displayName()],
                        'value'     => round((float) $rows->sum(fn (Trip $t) => TripFigures::rowRevenue($t)), 2),
                        'trips'     => $rows->map(fn (Trip $t) => [
                            'id' => $t->id, 'number' => $t->number, 'route' => $t->route?->displayName(), 'value' => TripFigures::rowRevenue($t),
                        ])->values(),
                    ];
                })->values(),
                'links' => $invoices->linkCollection(),
                'total' => $invoices->total(),
            ],
            'open'      => $open->map(fn (Trip $t) => [
                'id'          => $t->id,
                'number'      => $t->number,
                'customer'    => ['id' => $t->customer_id, 'name' => $t->customer?->displayName()],
                'route'       => $t->route?->displayName(),
                'vehicle'     => $t->vehicle ? ['number' => $t->vehicle->plate_number, 'letters' => $t->vehicle->plate_letters] : null,
                'settled_at'  => $t->settled_at?->toIso8601String(),
                'days'        => $t->settled_at ? (int) $t->settled_at->diffInDays($now, true) : null,
                'value'       => TripFigures::rowRevenue($t),
            ])->values(),
            'customers' => Customer::query()->orderBy('name_ar')->get(['id', 'name_ar', 'name_en'])
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->displayName()])->values(),
        ]);
    }

    public function store(Request $request, InvoiceService $service): RedirectResponse
    {
        $companyId = $request->user('web')->company_id;
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'number'      => ['required', 'string', 'max:60'],
            'issued_on'   => ['nullable', 'date'],
            'note'        => ['nullable', 'string', 'max:250'],
            'trip_ids'    => ['required', 'array', 'min:1', 'max:200'],
            'trip_ids.*'  => ['integer', Rule::exists('trips', 'id')->where('company_id', $companyId)],
        ]);

        return $this->attempt(function () use ($service, $data, $request) {
            $service->link((int) $data['customer_id'], $data['number'], $data['issued_on'] ?? null, $data['note'] ?? null, $data['trip_ids'], $request->user('web'));
        }, __('finance.ok.invoice_linked'));
    }

    public function update(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $companyId = $request->user('web')->company_id;
        $data = $request->validate([
            'number'      => ['required', 'string', 'max:60'],
            'issued_on'   => ['nullable', 'date'],
            'note'        => ['nullable', 'string', 'max:250'],
            'add'         => ['nullable', 'array', 'max:200'],
            'add.*'       => ['integer', Rule::exists('trips', 'id')->where('company_id', $companyId)],
            'remove'      => ['nullable', 'array', 'max:200'],
            'remove.*'    => ['integer'],
        ]);

        return $this->attempt(function () use ($service, $invoice, $data, $request) {
            $service->update($invoice, $data, $request->user('web'));
        }, __('finance.ok.invoice_saved'));
    }

    public function destroy(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->delete($invoice, $request->user('web'));

        return back()->with('success', __('finance.ok.invoice_deleted'));
    }
}
