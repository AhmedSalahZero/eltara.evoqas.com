<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\TripRoute;
use App\Models\TripRouteBudget;
use App\Services\CompanyDefaults;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\RouteController ( /office/routes )
//  Location: app/Http/Controllers/Office/RouteController.php
//
//  "Routes & budgets" (Scope §6.6): every route the company runs,
//  with its round-trip km, usual hours and its STANDARD BUDGET — the
//  expected cost per expense category. The list shows per route:
//    standard total · cash road costs (paid by the driver from custody)
//    · suggested custody (cash × (1 + buffer %), rounded up to 500)
//    · cost per km · how many customers have a price on it.
//  The standard budget is the same for every customer; the price per
//  customer lives in the rate cards.
//
//  Permissions: the scope's permission list has no separate "routes"
//  line, so routes follow "Customers & rate cards" (customers.view /
//  create / edit / delete) — rate cards are built on routes.
// ══════════════════════════════════════════════════════════════════

class RouteController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = CompanyDefaults::ensure($request->user('web')->company_id);
        $search = trim((string) $request->query('search'));

        $routes = TripRoute::query()
            ->with('budgets.category')
            ->withCount('rateCards')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('origin_ar', 'like', "%{$search}%")->orWhere('origin_en', 'like', "%{$search}%")
                ->orWhere('destination_ar', 'like', "%{$search}%")->orWhere('destination_en', 'like', "%{$search}%")))
            ->orderByDesc('is_active')
            ->orderBy('origin_ar')
            ->get();

        return Inertia::render('Office/Routes/Index', [
            'routes'     => $routes->map(fn (TripRoute $r) => $this->row($r, $settings->custody_buffer_percent))->values(),
            'categories' => $this->categories(),
            'buffer'     => $settings->custody_buffer_percent,
            'overBudget' => $settings->over_budget_percent,
            'filters'    => ['search' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $budgets] = $this->validated($request);

        DB::transaction(function () use ($data, $budgets, $request) {
            $route = TripRoute::query()->create($data + ['created_by' => $request->user('web')->id]);
            $this->saveBudgets($route, $budgets);
            Audit::record('route.created', $route, ['after' => ['route' => $route->displayName('en'), 'km' => $route->km_round_trip, 'budget' => $budgets]]);
        });

        return back()->with('success', __('common.saved'));
    }

    public function update(Request $request, TripRoute $tripRoute): RedirectResponse
    {
        $route = $tripRoute;
        [$data, $budgets] = $this->validated($request);

        DB::transaction(function () use ($route, $data, $budgets) {
            $before = ['km' => $route->km_round_trip, 'budget' => $route->budgets()->pluck('amount', 'expense_category_id')->map(fn ($v) => (float) $v)->all()];
            $route->fill($data)->save();
            $this->saveBudgets($route, $budgets);
            Audit::record('route.updated', $route, ['before' => $before, 'after' => ['km' => $route->km_round_trip, 'budget' => $budgets]]);
        });

        return back()->with('success', __('common.saved'));
    }

    public function destroy(TripRoute $tripRoute): RedirectResponse
    {
        $route = $tripRoute;
        if ($route->rateCards()->exists()) {
            return back()->with('error', __('errors.route_has_prices'));
        }

        try {
            DB::transaction(function () use ($route) {
                Audit::record('route.deleted', $route, ['before' => ['route' => $route->displayName('en')]]);
                $route->delete();
            });
        } catch (QueryException) {
            return back()->with('error', __('errors.in_use_cannot_delete'));
        }

        return back()->with('success', __('common.deleted'));
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** @return array{0: array, 1: array<int, float>} route fields, and category id => amount */
    private function validated(Request $request): array
    {
        $companyId = $request->user('web')->company_id;

        $data = $request->validate([
            'origin_ar'      => ['required', 'string', 'max:80'],
            'origin_en'      => ['nullable', 'string', 'max:80'],
            'destination_ar' => ['required', 'string', 'max:80'],
            'destination_en' => ['nullable', 'string', 'max:80'],
            'weight_tons'    => ['required', 'numeric', 'gt:0', 'max:1000'],
            'km_round_trip'  => ['required', 'integer', 'min:1', 'max:20000'],
            'usual_hours'    => ['nullable', 'numeric', 'min:0', 'max:500'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'is_active'      => ['required', 'boolean'],
            'budgets'        => ['present', 'array'],
            'budgets.*'      => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $validIds = ExpenseCategory::query()->where('company_id', $companyId)->pluck('id')->all();
        $budgets = collect($data['budgets'])
            ->filter(fn ($amount, $id) => in_array((int) $id, $validIds, true) && (float) $amount > 0)
            ->mapWithKeys(fn ($amount, $id) => [(int) $id => (float) $amount])
            ->all();
        unset($data['budgets']);

        return [$data, $budgets];
    }

    private function saveBudgets(TripRoute $route, array $budgets): void
    {
        TripRouteBudget::query()->where('trip_route_id', $route->id)->whereNotIn('expense_category_id', array_keys($budgets) ?: [0])->delete();

        foreach ($budgets as $categoryId => $amount) {
            TripRouteBudget::query()->updateOrCreate(
                ['trip_route_id' => $route->id, 'expense_category_id' => $categoryId],
                ['company_id' => $route->company_id, 'amount' => $amount],
            );
        }
    }

    private function row(TripRoute $r, float $buffer): array
    {
        $standard = $r->standardTotal();

        return [
            'id'             => $r->id,
            'name'           => $r->displayName(),
            'origin_ar'      => $r->origin_ar,
            'origin_en'      => $r->origin_en,
            'destination_ar' => $r->destination_ar,
            'destination_en' => $r->destination_en,
            'weight_tons'    => $r->weight_tons,
            'km_round_trip'  => $r->km_round_trip,
            'usual_hours'    => $r->usual_hours,
            'notes'          => $r->notes,
            'is_active'      => $r->is_active,
            'standard'       => $standard,
            'cash'           => $r->cashRoadCosts(),
            'custody'        => $r->suggestedCustody($buffer),
            'per_km'         => $r->km_round_trip ? round($standard / $r->km_round_trip, 2) : null,
            'customers'      => $r->rate_cards_count,
            'budgets'        => $r->budgets->mapWithKeys(fn ($b) => [$b->expense_category_id => $b->amount])->all(),
        ];
    }

    private function categories(): array
    {
        return ExpenseCategory::query()->ordered()->get()->map(fn (ExpenseCategory $c) => [
            'id' => $c->id, 'name' => $c->displayName(), 'icon' => $c->icon, 'cash_percent' => $c->cash_percent, 'is_active' => $c->is_active,
        ])->values()->all();
    }
}
