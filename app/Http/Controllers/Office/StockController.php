<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\RunsTripRules;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\Store;
use App\Services\Stock\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\StockController ( /office/stock )
//  Location: app/Http/Controllers/Office/StockController.php
//
//  The company workshop's store: what is on hand, receipts in,
//  and issues out (those are created by a maintenance job).
//  Permissions: maintenance.view to look, maintenance.create to
//  add a store, an item, or a receipt.
// ══════════════════════════════════════════════════════════════════

class StockController extends Controller
{
    use RunsTripRules;

    public function index(Request $request): Response
    {
        $storeId = $request->integer('store') ?: null;
        $stores = Store::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);
        if (! $storeId) {
            $storeId = $stores->firstWhere('is_default', true)?->id ?? $stores->first()?->id;
        }

        $balances = StockBalance::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with('item:id,name,unit')
            ->orderBy('stock_item_id')
            ->get();

        $moves = StockMove::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['item:id,name,unit', 'vehicle:id,plate_number,plate_letters'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Office/Stock/Index', [
            'store' => $storeId,
            'stores' => $stores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'default' => $s->is_default])->values(),
            'items' => StockItem::query()->orderBy('name')->get(['id', 'name', 'unit'])
                ->map(fn (StockItem $i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit])->values(),
            'balances' => $balances->map(fn (StockBalance $b) => [
                'id' => $b->id,
                'item_id' => $b->stock_item_id,
                'name' => $b->item?->name,
                'unit' => $b->item?->unit,
                'qty' => $b->qty,
                'value' => $b->value,
                'average' => $b->qty > 0 ? round($b->average(), 2) : 0,
            ])->values(),
            'moves' => $moves->map(fn (StockMove $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'item' => $m->item?->name,
                'unit' => $m->item?->unit,
                'qty' => $m->qty,
                'amount' => $m->amount,
                'supplier' => $m->supplier,
                'truck' => $m->vehicle ? trim($m->vehicle->plate_number.' '.$m->vehicle->plate_letters) : null,
                'at' => $m->occurred_at?->toIso8601String(),
            ])->values(),
            'totals' => [
                'qty' => round((float) $balances->sum('qty'), 3),
                'value' => round((float) $balances->sum('value'), 2),
            ],
        ]);
    }

    public function storeStore(Request $request, StockService $stock): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        return $this->attempt(fn () => $stock->addStore($data, $request->user('web')), __('stock.ok.saved'));
    }

    public function storeItem(Request $request, StockService $stock): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'unit' => ['required', 'in:litre,piece,kg'],
        ]);

        return $this->attempt(fn () => $stock->addItem($data, $request->user('web')), __('stock.ok.saved'));
    }

    public function receive(Request $request, StockService $stock): RedirectResponse
    {
        $companyId = $request->user('web')->company_id;
        $data = $request->validate([
            'store_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('stores', 'id')->where('company_id', $companyId)],
            'stock_item_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:120'],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        return $this->attempt(fn () => $stock->receive($data, $request->user('web')), __('stock.ok.received'));
    }
}
