<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\RateCard;
use App\Models\TripRoute;
use App\Services\AccountInvitation;
use App\Services\CompanyDefaults;
use App\Support\Audit;
use App\Support\EgyptPhone;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\CustomerController ( /office/customers )
//  Location: app/Http/Controllers/Office/CustomerController.php
//
//  "Customers & rate cards" (Scope §6.9) — the demo's screen:
//  customers on one side; for the selected one, its rate card and
//  its client-portal users on the other.
//
//    index / store / update / destroy      → customers
//    storeRate / updateRate / destroyRate  → rate-card lines: the price
//        agreed with this customer for one route (round trip). Each
//        line shows the route's standard cost, the expected direct
//        profit, and the profit after G&A (route km × the G&A per km
//        estimate in Company settings). Every price change is audited.
//    storeClient / toggleClient / resendClient → client-portal users:
//        the company creates the customer's first portal user (usually
//        the customer's account admin), who receives an activation
//        email (Scope §17 #5: they do not count in the office limit).
//
//  Permissions: customers.view / create / edit / delete.
// ══════════════════════════════════════════════════════════════════

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user('web')->company_id;
        $settings = CompanyDefaults::ensure($companyId);
        $search = trim((string) $request->query('search'));

        $customers = Customer::query()
            ->withCount('rateCards')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name_ar', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%")))
            ->orderByDesc('is_active')
            ->orderBy('name_ar')
            ->get();

        $selected = $customers->firstWhere('id', (int) $request->query('customer')) ?? $customers->first();

        return Inertia::render('Office/Customers/Index', [
            'customers' => $customers->map(fn (Customer $c) => $this->row($c))->values(),
            'selected'  => $selected ? $this->detail($selected, $settings) : null,
            'routes'    => TripRoute::query()->where('is_active', true)->with('budgets')->orderBy('origin_ar')->get()
                ->map(fn (TripRoute $r) => ['id' => $r->id, 'name' => $r->displayName(), 'km' => $r->km_round_trip, 'standard' => $r->standardTotal()])->values(),
            'gaRate'    => $settings->ga_rate_estimate,
            'filters'   => ['search' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = DB::transaction(function () use ($request) {
            $customer = Customer::query()->create($this->validated($request) + ['is_active' => true, 'created_by' => $request->user('web')->id]);
            Audit::record('customer.created', $customer, ['after' => ['name' => $customer->name_ar]]);

            return $customer;
        });

        return redirect()->route('office.customers.index', ['customer' => $customer->id])->with('success', __('common.saved'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request) + ['is_active' => $request->boolean('is_active', true)];
        $customer->fill($data);
        $changes = $customer->getDirty();
        $customer->save();

        if ($changes) {
            Audit::record('customer.updated', $customer, ['after' => $changes]);
        }

        return back()->with('success', __('common.saved'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        try {
            DB::transaction(function () use ($customer) {
                Audit::record('customer.deleted', $customer, ['before' => ['name' => $customer->name_ar]]);
                $customer->delete();
            });
        } catch (QueryException) {
            return back()->with('error', __('errors.in_use_cannot_delete'));
        }

        return redirect()->route('office.customers.index')->with('success', __('common.deleted'));
    }

    // ── Rate card ──────────────────────────────────────────────────

    public function storeRate(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'trip_route_id' => ['required', 'integer',
                Rule::exists('trip_routes', 'id')->where('company_id', $customer->company_id),
                Rule::unique('rate_cards')->where('customer_id', $customer->id)],
            'price' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'notes' => ['nullable', 'string', 'max:250'],
        ], ['trip_route_id.unique' => __('errors.route_already_priced')]);

        $rate = RateCard::query()->create($data + ['customer_id' => $customer->id, 'updated_by' => $request->user('web')->id]);
        Audit::record('rate_card.created', $rate, ['after' => ['customer' => $customer->name_ar, 'route_id' => $rate->trip_route_id, 'price' => $rate->price]]);

        return back()->with('success', __('common.saved'));
    }

    public function updateRate(Request $request, Customer $customer, RateCard $rate): RedirectResponse
    {
        abort_unless($rate->customer_id === $customer->id, 404);

        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'notes' => ['nullable', 'string', 'max:250'],
        ]);

        $before = $rate->price;
        $rate->fill($data + ['updated_by' => $request->user('web')->id])->save();

        if ((float) $before !== (float) $rate->price) {
            Audit::record('rate_card.price_changed', $rate, ['before' => ['price' => $before], 'after' => ['price' => $rate->price]]);
        }

        return back()->with('success', __('common.saved'));
    }

    public function destroyRate(Customer $customer, RateCard $rate): RedirectResponse
    {
        abort_unless($rate->customer_id === $customer->id, 404);

        Audit::record('rate_card.deleted', $rate, ['before' => ['route_id' => $rate->trip_route_id, 'price' => $rate->price]]);
        $rate->delete();

        return back()->with('success', __('common.deleted'));
    }

    // ── Client-portal users ────────────────────────────────────────

    public function storeClient(Request $request, Customer $customer, AccountInvitation $invitation): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => $request->filled('phone') ? EgyptPhone::normalize($request->input('phone')) : null,
        ]);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:150', 'unique:client_users,email', 'unique:users,email'],
            'phone'     => ['nullable', 'regex:'.EgyptPhone::PATTERN, 'unique:client_users,phone', 'unique:users,phone'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'language'  => ['required', 'in:ar,en'],
        ], [
            'email.unique' => __('errors.email_taken'),
            'phone.unique' => __('errors.phone_taken'),
        ]);

        $client = DB::transaction(function () use ($data, $customer) {
            $client = ClientUser::query()->create($data + [
                'company_id'       => $customer->company_id,
                'customer_id'      => $customer->id,
                'password'         => Str::password(32),
                'is_account_admin' => ! ClientUser::query()->where('customer_id', $customer->id)->exists(),
                'is_active'        => true,
            ]);
            Audit::record('client_user.invited', $client, ['after' => ['email' => $client->email, 'customer' => $customer->name_ar]]);

            return $client;
        });

        $invitation->sendToClient($client);

        return back()->with('success', __('common.user_invited', ['email' => $client->email]));
    }

    public function toggleClient(Customer $customer, ClientUser $client): RedirectResponse
    {
        abort_unless($client->customer_id === $customer->id, 404);

        $client->forceFill(['is_active' => ! $client->is_active])->save();
        Audit::record($client->is_active ? 'client_user.reactivated' : 'client_user.suspended', $client);

        return back()->with('success', __($client->is_active ? 'common.user_reactivated' : 'common.user_suspended', ['name' => $client->name]));
    }

    public function resendClient(Customer $customer, ClientUser $client, AccountInvitation $invitation): RedirectResponse
    {
        abort_unless($client->customer_id === $customer->id, 404);

        $invitation->sendToClient($client);

        return back()->with('success', __('common.activation_resent', ['email' => $client->email]));
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        $request->merge([
            'contact_phone' => $request->filled('contact_phone') ? (EgyptPhone::normalize($request->input('contact_phone')) ?? $request->input('contact_phone')) : null,
            'contact_email' => $request->filled('contact_email') ? Str::lower(trim((string) $request->input('contact_email'))) : null,
        ]);

        return $request->validate([
            'name_ar'             => ['required', 'string', 'max:150'],
            'name_en'             => ['nullable', 'string', 'max:150'],
            'payment_terms_days'  => ['required', 'integer', 'min:0', 'max:365'],
            'may_pay_driver_cash' => ['required', 'boolean'],
            'contact_name'        => ['nullable', 'string', 'max:120'],
            'contact_phone'       => ['nullable', 'string', 'max:20'],
            'contact_email'       => ['nullable', 'email', 'max:150'],
            'address'             => ['nullable', 'string', 'max:250'],
            'tax_number'          => ['nullable', 'string', 'max:30'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function row(Customer $c): array
    {
        return [
            'id'                  => $c->id,
            'name'                => $c->displayName(),
            'name_ar'             => $c->name_ar,
            'name_en'             => $c->name_en,
            'payment_terms_days'  => $c->payment_terms_days,
            'may_pay_driver_cash' => $c->may_pay_driver_cash,
            'contact_name'        => $c->contact_name,
            'contact_phone'       => $c->contact_phone,
            'contact_email'       => $c->contact_email,
            'address'             => $c->address,
            'tax_number'          => $c->tax_number,
            'notes'               => $c->notes,
            'is_active'           => $c->is_active,
            'routes_count'        => $c->rate_cards_count,
        ];
    }

    private function detail(Customer $customer, CompanySetting $settings): array
    {
        $rates = RateCard::query()->where('customer_id', $customer->id)->with('route.budgets.category')->get()
            ->sortBy(fn (RateCard $r) => $r->route?->origin_ar)
            ->map(function (RateCard $r) use ($settings) {
                $standard = $r->route->standardTotal();
                $direct = $r->price - $standard;

                return [
                    'id'        => $r->id,
                    'route_id'  => $r->trip_route_id,
                    'route'     => $r->route->displayName(),
                    'km'        => $r->route->km_round_trip,
                    'price'     => $r->price,
                    'standard'  => $standard,
                    'direct'    => $direct,
                    'after_ga'  => $settings->ga_rate_estimate !== null ? $direct - $r->route->km_round_trip * $settings->ga_rate_estimate : null,
                    'per_km'    => $r->route->km_round_trip ? round($r->price / $r->route->km_round_trip, 2) : null,
                    'notes'     => $r->notes,
                ];
            })->values();

        $clients = ClientUser::query()->where('customer_id', $customer->id)->orderByDesc('is_account_admin')->orderBy('name')->get()
            ->map(fn (ClientUser $u) => [
                'id'            => $u->id,
                'name'          => $u->name,
                'email'         => $u->email,
                'phone'         => $u->phone,
                'job_title'     => $u->job_title,
                'is_admin'      => $u->is_account_admin,
                'is_active'     => $u->is_active,
                'activated'     => $u->isActivated(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])->values();

        return $this->row($customer) + ['rates' => $rates, 'clients' => $clients];
    }
}
