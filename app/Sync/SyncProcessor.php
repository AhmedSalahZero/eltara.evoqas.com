<?php

namespace App\Sync;

use App\Models\Driver;
use App\Models\SyncReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SyncProcessor (receives the Driver App's offline entries)
//  Location: app/Sync/SyncProcessor.php
//
//  Scope §12 "Offline sync: entries are queued on the phone and
//  uploaded in order; duplicate submissions are prevented".
//
//  The phone sends a batch: [{uuid, type, payload, recorded_at}, …]
//  in the order the driver recorded them. For EACH entry, in order:
//
//    already has a receipt → 'duplicate'  (the phone retried after a
//                            lost answer; nothing is recorded again)
//    unknown type          → 'rejected'   (never guessed)
//    payload not valid     → 'rejected'   with the reason
//    handler refuses       → 'rejected'   with the handler's reason
//    otherwise             → 'applied'    handler result returned
//
//  The entry and its receipt are saved in ONE transaction: either
//  both exist or neither does. Two uploads of the same entry arriving
//  at the same moment cannot both win — the unique uuid index lets
//  only one receipt in, and the other becomes 'duplicate'.
//
//    unexpected fault  → 'failed'     the phone keeps the entry and tries again;
//                        the entries after it come back as 'retry' (order kept)
//
//  One refused entry never blocks the ones after it. The phone deletes
//  'applied' and 'duplicate' entries, and shows 'rejected' ones to
//  the driver.
// ══════════════════════════════════════════════════════════════════

final class SyncProcessor
{
    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const REJECTED = 'rejected';

    /** An unexpected server fault on this entry (nothing was saved). The phone keeps it and tries again. */
    public const FAILED = 'failed';

    /** Not tried at all, because an entry before it failed and the order must be kept. The phone keeps it. */
    public const RETRY = 'retry';

    /**
     * @param  list<array{uuid:string,type:string,payload?:array,recorded_at?:?string}>  $items
     * @return list<array{uuid:string,status:string,message?:string,result?:array}>
     */
    public function process(Driver $driver, array $items): array
    {
        $results = [];

        $stopped = false;

        foreach ($items as $item) {
            // After an unexpected fault the rest of the batch is not tried: the entries depend on each
            // other's order (accept → depart → expense …), so skipping one could wrongly refuse the next.
            if ($stopped) {
                $results[] = ['uuid' => $item['uuid'], 'status' => self::RETRY];

                continue;
            }

            try {
                $results[] = ['uuid' => $item['uuid'], ...$this->one($driver, $item)];
            } catch (Throwable $e) {
                // A fault that is not a business rule (database hiccup, a bug). It must never turn the whole
                // upload into a server error — entries already saved in this batch would be answered with
                // nothing. Log it for the developer; nothing was saved for this entry (its transaction rolled back).
                report($e);
                $stopped = true;
                $results[] = ['uuid' => $item['uuid'], 'status' => self::FAILED, 'message' => __('errors.server')];
            }
        }

        $driver->forceFill(['last_sync_at' => now()])->saveQuietly();

        return $results;
    }

    private function one(Driver $driver, array $item): array
    {
        $existing = SyncReceipt::query()->where('uuid', $item['uuid'])->first();

        if ($existing) {
            // Another driver's uuid is never revealed or reused.
            if ($existing->driver_id !== $driver->id) {
                return ['status' => self::REJECTED, 'message' => __('errors.forbidden')];
            }

            return ['status' => self::DUPLICATE, 'result' => $existing->result ?? []];
        }

        // Read the whole list: entry types contain dots ("driver.preferences"),
        // which config() would otherwise treat as a path.
        $handlerClass = config('sync.handlers', [])[$item['type']] ?? null;

        if (! $handlerClass || ! is_subclass_of($handlerClass, SyncHandler::class)) {
            return $this->reject($driver, $item, 'Unknown entry type.');
        }

        /** @var SyncHandler $handler */
        $handler = app($handlerClass);
        $recordedAt = isset($item['recorded_at']) ? $this->bounded(Carbon::parse($item['recorded_at'])) : null;

        try {
            $payload = Validator::make($item['payload'] ?? [], $handler->rules())->validate();
        } catch (ValidationException $e) {
            return $this->reject($driver, $item, collect($e->errors())->flatten()->first());
        }

        try {
            $result = DB::transaction(function () use ($driver, $item, $handler, $payload, $recordedAt) {
                $result = $handler->handle($driver, $payload, $recordedAt);
                $this->receipt($driver, $item, SyncReceipt::APPLIED, $result, $recordedAt);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return ['status' => self::DUPLICATE, 'result' => []];
        } catch (SyncRejected $e) {
            return $this->reject($driver, $item, $e->getMessage());
        }

        return ['status' => self::APPLIED, 'result' => $result];
    }

    /**
     * A phone's clock is not trusted: an entry cannot be dated in the future, nor further back than
     * the allowed days (config eltara.sync.max_backdate_days). Anything outside is moved to the edge.
     */
    private function bounded(Carbon $at): Carbon
    {
        $now = now();
        $oldest = $now->copy()->subDays((int) config('eltara.sync.max_backdate_days', 7));

        return $at->greaterThan($now) ? $now : ($at->lessThan($oldest) ? $oldest : $at);
    }

    private function reject(Driver $driver, array $item, string $message): array
    {
        try {
            $this->receipt($driver, $item, SyncReceipt::REJECTED, ['message' => $message], null);
        } catch (UniqueConstraintViolationException) {
            // Arrived twice at the same moment — the first answer stands.
        }

        return ['status' => self::REJECTED, 'message' => $message];
    }

    private function receipt(Driver $driver, array $item, string $status, array $result, ?\DateTimeInterface $recordedAt): void
    {
        SyncReceipt::query()->create([
            'uuid'         => $item['uuid'],
            'company_id'   => $driver->company_id,
            'driver_id'    => $driver->id,
            'type'         => mb_substr((string) $item['type'], 0, 60),
            'status'       => $status,
            'result'       => $result,
            'recorded_at'  => $recordedAt,
            'processed_at' => now(),
        ]);
    }
}
