<?php

namespace App\Services\Stock;

use App\Models\MaintenanceJob;
use App\Models\MaintenanceJobLine;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\Store;
use App\Models\User;
use App\Services\Trips\TripRuleException;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  El Tara — StockService (receipts, issues, the workshop store)
//  Location: app/Services/Stock/StockService.php
//
//  Average cost = value on hand ÷ quantity on hand.
//  A receipt raises both. An issue takes that average and lowers
//  both. Issuing more than is on hand is refused. The last of an
//  item takes whatever value is left, so no piastres get stuck.
// ══════════════════════════════════════════════════════════════════

final class StockService
{
    public function ensureDefault(int $companyId): Store
    {
        $store = Store::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('is_default', true)->first();
        if ($store) {
            return $store;
        }

        return Store::query()->create([
            'company_id' => $companyId,
            'name' => 'ورشة الشركة',
            'is_default' => true,
        ]);
    }

    /** @param array{store_id:int, name:string} $data */
    public function addStore(array $data, User $user): Store
    {
        $name = $this->name($data['name'] ?? null);
        if ($name === null) {
            throw TripRuleException::because('stock.name_required');
        }

        $store = Store::query()->create([
            'company_id' => $user->company_id,
            'name' => $name,
            'is_default' => false,
        ]);
        Audit::record('stock.created', $store, ['after' => ['name' => $store->name]]);

        return $store;
    }

    /** @param array{name:string, unit:string} $data */
    public function addItem(array $data, User $user): StockItem
    {
        $name = $this->name($data['name'] ?? null);
        $unit = (string) ($data['unit'] ?? '');
        if ($name === null) {
            throw TripRuleException::because('stock.name_required');
        }
        if (! in_array($unit, StockItem::UNITS, true)) {
            throw TripRuleException::because('stock.unit_required');
        }
        if (StockItem::query()->where('name', $name)->exists()) {
            throw TripRuleException::because('errors.list_item_exists');
        }

        $item = StockItem::query()->create([
            'company_id' => $user->company_id,
            'name' => $name,
            'unit' => $unit,
        ]);
        Audit::record('stock.created', $item, ['after' => ['name' => $item->name, 'unit' => $item->unit]]);

        return $item;
    }

    /**
     * Goods in. Average cost is worked out again from the new total.
     *
     * @param  array{store_id:int, stock_item_id:int, qty:mixed, unit_price:mixed, supplier?:?string, occurred_at?:mixed, note?:?string}  $data
     */
    public function receive(array $data, User $user): StockMove
    {
        return DB::transaction(function () use ($data, $user) {
            $store = $this->store((int) ($data['store_id'] ?? 0));
            $item = $this->item((int) ($data['stock_item_id'] ?? 0));
            $qty = $this->qty($data['qty'] ?? null);
            $price = $this->money($data['unit_price'] ?? null);
            if ($qty === null || $price === null) {
                throw TripRuleException::because('stock.qty_and_price');
            }

            $amount = round($qty * $price, 2);
            $balance = $this->balance($store, $item);
            $balance->qty = round($balance->qty + $qty, 3);
            $balance->value = round($balance->value + $amount, 2);
            $balance->save();

            $move = StockMove::query()->create([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'stock_item_id' => $item->id,
                'direction' => 'in',
                'qty' => $qty,
                'unit_cost' => $price,
                'amount' => $amount,
                'supplier' => $this->name($data['supplier'] ?? null, 120),
                'occurred_at' => isset($data['occurred_at']) && $data['occurred_at'] !== '' ? Carbon::parse($data['occurred_at']) : now(),
                'note' => $this->name($data['note'] ?? null, 250),
                'created_by' => $user->id,
            ]);
            Audit::record('stock.recorded', $move, ['after' => ['item' => $item->name, 'qty' => $qty, 'amount' => $amount]]);

            return $move;
        });
    }

    /** Issue every unissued line of a company-workshop job. Returns the total cost. */
    public function issueJob(MaintenanceJob $job, User $user): float
    {
        $store = $this->store((int) $job->store_id);
        $total = 0.0;

        foreach ($job->lines()->whereNull('stock_move_id')->get() as $line) {
            $issued = $this->issue($store, $line, $job, $user);
            $total += $issued;
        }

        return round($total, 2);
    }

    /** Put issued parts back and drop the issue lines' costs. Used when a job is reopened or deleted. */
    public function reverseJob(MaintenanceJob $job): void
    {
        foreach ($job->lines()->whereNotNull('stock_move_id')->get() as $line) {
            $move = StockMove::query()->find($line->stock_move_id);
            if ($move && $move->direction === 'out') {
                $balance = StockBalance::query()->where('store_id', $move->store_id)->where('stock_item_id', $move->stock_item_id)->lockForUpdate()->first();
                if ($balance) {
                    $balance->qty = round($balance->qty + $move->qty, 3);
                    $balance->value = round($balance->value + $move->amount, 2);
                    $balance->save();
                }
                $move->delete();
            }
            $line->forceFill(['unit_cost' => null, 'amount' => null, 'stock_move_id' => null])->save();
        }
    }

    private function issue(Store $store, MaintenanceJobLine $line, MaintenanceJob $job, User $user): float
    {
        $item = $this->item((int) $line->stock_item_id);
        $balance = StockBalance::query()->where('store_id', $store->id)->where('stock_item_id', $item->id)->lockForUpdate()->first();
        $qty = round((float) $line->qty, 3);

        if (! $balance || $qty > $balance->qty + 0.0001) {
            throw TripRuleException::because('stock.not_enough', ['item' => $item->name]);
        }

        $takeAll = $qty >= $balance->qty - 0.0001;
        $amount = $takeAll ? round($balance->value, 2) : round($qty * $balance->average(), 2);
        $unit = $qty > 0 ? round($amount / $qty, 4) : 0.0;

        $balance->qty = round($balance->qty - ($takeAll ? $balance->qty : $qty), 3);
        $balance->value = round($balance->value - $amount, 2);
        $balance->save();

        $move = StockMove::query()->create([
            'company_id' => $store->company_id,
            'store_id' => $store->id,
            'stock_item_id' => $item->id,
            'direction' => 'out',
            'qty' => $qty,
            'unit_cost' => $unit,
            'amount' => $amount,
            'maintenance_job_id' => $job->id,
            'vehicle_id' => $job->vehicle_id,
            'occurred_at' => $job->completed_at ?? now(),
            'created_by' => $user->id,
        ]);
        $line->forceFill(['unit_cost' => $unit, 'amount' => $amount, 'stock_move_id' => $move->id])->save();

        return $amount;
    }

    private function balance(Store $store, StockItem $item): StockBalance
    {
        $balance = StockBalance::query()->where('store_id', $store->id)->where('stock_item_id', $item->id)->lockForUpdate()->first();
        if ($balance) {
            return $balance;
        }

        return StockBalance::query()->create([
            'company_id' => $store->company_id,
            'store_id' => $store->id,
            'stock_item_id' => $item->id,
            'qty' => 0,
            'value' => 0,
        ]);
    }

    private function store(int $id): Store
    {
        $store = Store::query()->find($id);
        if (! $store) {
            throw TripRuleException::because('stock.store_required');
        }

        return $store;
    }

    private function item(int $id): StockItem
    {
        $item = StockItem::query()->find($id);
        if (! $item) {
            throw TripRuleException::because('stock.item_required');
        }

        return $item;
    }

    private function qty(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return round((float) $value, 3);
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function name(mixed $value, int $max = 80): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
