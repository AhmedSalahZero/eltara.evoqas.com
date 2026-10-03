<?php

namespace App\Services\Closing;

use App\Models\GaEntry;
use App\Models\MonthClose;
use App\Models\Trip;
use App\Models\TripAllocation;
use App\Models\TripCharge;
use App\Models\TripExpense;
use App\Models\User;
use App\Services\Trips\TripFigures;
use App\Services\Trips\TripRuleException;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

// ══════════════════════════════════════════════════════════════════
//  El Tara — MonthCloseService (G&A, the allocation rate, closing)
//  Location: app/Services/Closing/MonthCloseService.php
//
//  Scope §6.13 — month close & true profit:
//    1. the CFO enters the month's G&A lines (by hand or from Excel)
//    2. allocation rate = total G&A ÷ own-fleet km in the month
//    3. each trip's share = its km in that month × the rate
//    4. true profit = direct profit − G&A share
//    5. a trip spanning two months is split (Allocator / MonthSplit)
//    6. until the month is closed, true profit is an ESTIMATE
//    7. CLOSING locks the month: the G&A lines and the trips' parts
//       are frozen. RE-OPENING needs a special permission, a reason,
//       and is written to the audit log.
//
//  The month's own figures — revenue, direct profit, true profit —
//  count the trips DELIVERED in that month (a trip's revenue belongs
//  to the month it was delivered), and true profit = direct profit −
//  the month's G&A. (All G&A is spread over own-fleet km, so what the
//  trips carry adds up to the G&A entered.)
//
//  Rules refuse with TripRuleException (lang/*/finance.php 'close').
// ══════════════════════════════════════════════════════════════════

final class MonthCloseService
{
    /** Most trip rows one month screen lists. */
    private const MAX_ROWS = 1500;

    public function __construct(
        private readonly Allocator $allocator,
        private readonly TrueProfit $trueProfit,
    ) {}

    // ── Months ─────────────────────────────────────────────────────

    /** "2026-09" → 1 Sept 2026 00:00. Anything else (or a future month) → this month. */
    public static function parseMonth(?string $key): Carbon
    {
        $current = Carbon::now()->startOfMonth();

        if (! is_string($key) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key)) {
            return $current;
        }

        $month = Carbon::createFromFormat('Y-m-d H:i:s', $key.'-01 00:00:00')->startOfMonth();

        return $month->greaterThan($current) ? $current : $month;
    }

    public static function monthKey(Carbon $month): string
    {
        return $month->format('Y-m');
    }

    private static function dbMonth(Carbon $month): string
    {
        return $month->format('Y-m-01');
    }

    public function closeRow(Carbon $month): ?MonthClose
    {
        return MonthClose::query()->where('month', self::dbMonth($month))->first();
    }

    public function isClosed(Carbon $month): bool
    {
        return (bool) $this->closeRow($month)?->isClosed();
    }

    /** The last 12 months with their state, for the month picker. */
    public function months(): array
    {
        $rows = MonthClose::query()->get()->keyBy(fn ($m) => substr((string) $m->month, 0, 7));
        $out = [];

        for ($i = 0; $i < 12; $i++) {
            $m = Carbon::now()->startOfMonth()->subMonths($i);
            $key = $m->format('Y-m');
            $out[] = ['key' => $key, 'state' => isset($rows[$key]) ? ($rows[$key]->isClosed() ? 'closed' : 'reopened') : 'open', 'current' => $i === 0];
        }

        return $out;
    }

    // ── G&A lines ──────────────────────────────────────────────────

    public function lines(Carbon $month): array
    {
        return GaEntry::query()->where('month', self::dbMonth($month))->orderBy('id')->get()
            ->map(fn (GaEntry $g) => ['id' => $g->id, 'code' => $g->code, 'label' => $g->displayLabel(), 'amount' => $g->amount])->values()->all();
    }

    public function gaTotal(Carbon $month): float
    {
        return round((float) GaEntry::query()->where('month', self::dbMonth($month))->sum('amount'), 2);
    }

    /** @param array{code?: ?string, label?: ?string, amount: float|string} $data */
    public function addLine(Carbon $month, array $data, User $user): GaEntry
    {
        $this->assertOpen($month);
        $new = $this->lineData($month, $data);

        // A standard line (rent, tyres …) exists ONCE per month: adding it again adds the amount to the line
        // that is already there (the database refuses a second row, so two cannot sit side by side).
        if ($new['code'] !== null && ($existing = $this->standardLine($month, $new['code']))) {
            $before = ['label' => $existing->label, 'amount' => $existing->amount];
            $existing->amount = round((float) $existing->amount + $new['amount'], 2);
            $existing->save();
            Audit::record('ga.updated', $existing, ['before' => $before, 'after' => ['label' => $existing->label, 'amount' => $existing->amount], 'added' => $new['amount']]);

            return $existing;
        }

        $line = GaEntry::query()->create($new + ['created_by' => $user->id]);
        Audit::record('ga.created', $line, ['after' => ['month' => self::monthKey($month), 'label' => $line->label, 'amount' => $line->amount]]);

        return $line;
    }

    public function updateLine(GaEntry $line, array $data, User $user): GaEntry
    {
        $month = Carbon::parse($line->month)->startOfMonth();
        $this->assertOpen($month);

        $new = $this->lineData($month, $data);

        if ($new['code'] !== null && GaEntry::query()->where('month', self::dbMonth($month))->where('code', $new['code'])->whereKeyNot($line->id)->exists()) {
            throw TripRuleException::because('finance.close.line_exists');
        }

        $before = ['label' => $line->label, 'amount' => $line->amount];
        $line->fill($new)->save();
        Audit::record('ga.updated', $line, ['before' => $before, 'after' => ['label' => $line->label, 'amount' => $line->amount]]);

        return $line;
    }

    public function deleteLine(GaEntry $line, User $user): void
    {
        $this->assertOpen(Carbon::parse($line->month)->startOfMonth());
        Audit::record('ga.deleted', $line, ['before' => ['month' => substr((string) $line->month, 0, 7), 'label' => $line->label, 'amount' => $line->amount]]);
        $line->delete();
    }

    /**
     * Lines from an Excel / CSV file: column A = the name, column B = the amount.
     * A header row and empty or non-numeric rows are skipped. Returns how many lines were added.
     */
    public function importLines(Carbon $month, string $path, bool $replace, User $user): int
    {
        $this->assertOpen($month);

        try {
            $rows = $this->readSheet($path);
        } catch (\Throwable) {
            throw TripRuleException::because('finance.close.import_bad_file');
        }

        $lines = [];
        foreach (array_slice($rows, 0, 400) as $row) {
            // A name that starts like a formula ("=…", "+…", "-…", "@…") loses that first character,
            // so it can never act as one if it is exported again later (audit Q25).
            $label = trim(ltrim(trim((string) ($row[0] ?? '')), "=+-@\t\r"));
            $raw = str_replace([',', ' ', "\u{00A0}"], '', (string) ($row[1] ?? ''));

            if ($label === '' || ! is_numeric($raw) || (float) $raw <= 0) {
                continue;
            }

            $lines[] = ['code' => $this->codeFor($label), 'label' => $label, 'amount' => (float) $raw];
        }

        if ($lines === []) {
            throw TripRuleException::because('finance.close.import_empty');
        }
        if (count($lines) > 200) {
            throw TripRuleException::because('finance.close.import_too_many');
        }

        DB::transaction(function () use ($month, $lines, $replace, $user) {
            if ($replace) {
                GaEntry::query()->where('month', self::dbMonth($month))->delete();
            }
            foreach ($lines as $line) {
                $this->addLine($month, $line, $user);
            }
            Audit::record('ga.imported', null, ['month' => self::monthKey($month), 'lines' => count($lines), 'replaced' => $replace, 'total' => round(array_sum(array_column($lines, 'amount')), 2)], $user->company_id);
        });

        return count($lines);
    }

    /**
     * The first two columns of the first sheet, as plain values (max 400 rows).
     *
     * Formulas are NEVER calculated here: an uploaded file could hold a formula that makes the server
     * fetch a web address or work for minutes. For a formula cell the value Excel saved with it (the
     * last result) is used. Only Excel (.xlsx) and CSV are read.
     *
     * @return list<array{0:mixed,1:mixed}>
     */
    private function readSheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path, [IOFactory::READER_XLSX, IOFactory::READER_CSV]);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();

        $rows = [];
        $last = min($sheet->getHighestDataRow(), 400);

        for ($r = 1; $r <= $last; $r++) {
            $row = [];
            foreach (['A', 'B'] as $col) {
                $cell = $sheet->cellExists("{$col}{$r}") ? $sheet->getCell("{$col}{$r}") : null;
                $row[] = $cell === null ? null : ($cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue());
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** The standard line with this code in this month, if there is one. */
    private function standardLine(Carbon $month, string $code): ?GaEntry
    {
        return GaEntry::query()->where('month', self::dbMonth($month))->where('code', $code)->first();
    }

    private function lineData(Carbon $month, array $data): array
    {
        $code = $data['code'] ?? null;
        $code = $code !== null && isset(GaEntry::STANDARD[$code]) ? $code : null;
        $label = trim((string) ($data['label'] ?? ''));
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw TripRuleException::because('finance.close.amount_positive');
        }
        if ($code === null && $label === '') {
            throw TripRuleException::because('finance.close.label_required');
        }

        return [
            'month'  => self::dbMonth($month),
            'code'   => $code,
            'label'  => mb_substr($code ? GaEntry::STANDARD[$code][0] : $label, 0, 120),
            'amount' => $amount,
        ];
    }

    /** A name typed in an Excel file → the standard line it matches (Arabic or English), or null. */
    private function codeFor(string $label): ?string
    {
        $needle = mb_strtolower(trim($label));

        foreach (GaEntry::STANDARD as $code => [$ar, $en]) {
            if ($needle === mb_strtolower($ar) || $needle === mb_strtolower($en)) {
                return $code;
            }
        }

        return null;
    }

    private function assertOpen(Carbon $month): void
    {
        if ($this->isClosed($month)) {
            throw TripRuleException::because('finance.close.month_locked');
        }
    }

    // ── The month screen ───────────────────────────────────────────

    /**
     * Everything the month-close screen shows: the G&A lines, the allocation
     * flow (G&A ÷ km = rate), the month's revenue / direct profit / true profit
     * and one row per trip that ran in the month.
     */
    public function summary(Carbon $month, int $companyId): array
    {
        $key = self::monthKey($month);
        $row = $this->closeRow($month);
        $closed = (bool) $row?->isClosed();
        $now = Carbon::now();

        $rule = $closed && $row->split_rule ? $row->split_rule : Allocator::rule($companyId);
        $includeHired = $closed && $row->basis ? $row->basis === 'all_km' : Allocator::includesHired($companyId);

        $trips = $closed
            ? Trip::query()->whereIn('trips.id', TripAllocation::query()->where('month', self::dbMonth($month))->select('trip_id'))
            : $this->allocator->tripsOverlapping($month, $includeHired);

        $trips = TripFigures::withMoney($trips->select('trips.*'))
            ->with(['allocations', 'vehicle:id,plate_number,plate_letters,ownership', 'customer:id,name_ar,name_en'])
            ->orderBy('trips.loading_at')->limit(self::MAX_ROWS)->get();

        // Each trip's part in THIS month.
        $parts = [];
        $km = 0.0;
        foreach ($trips as $trip) {
            $effective = $this->allocator->effective($trip, $rule, $trip->allocations, $now);
            $part = $effective[$key] ?? null;

            if ($part === null || $part['km'] <= 0) {
                continue;
            }
            $parts[$trip->id] = ['trip' => $trip, 'part' => $part, 'count' => count($effective)];
            $km += $part['km'];
        }
        $km = round($km, 2);

        $gaTotal = $closed ? (float) $row->ga_total : $this->gaTotal($month);
        $rate = $closed ? (float) $row->rate : ($km > 0 && $gaTotal > 0 ? round($gaTotal / $km, 6) : null);

        $context = $this->trueProfit->context($companyId, $rate !== null && ! $closed ? [$key => $rate] : []);
        $rows = [];
        foreach ($parts as $item) {
            /** @var Trip $trip */
            $trip = $item['trip'];
            $part = $item['part'];
            $direct = round(TripFigures::rowRevenue($trip) - (float) $trip->cost_total, 2);
            $true = $this->trueProfit->forTrip($trip, $direct, $now, $context);

            $rows[] = [
                'id'          => $trip->id,
                'number'      => $trip->number,
                'status'      => $trip->status,
                'customer'    => $trip->customer?->displayName(),
                'vehicle'     => $trip->vehicle ? ['number' => $trip->vehicle->plate_number, 'letters' => $trip->vehicle->plate_letters] : null,
                'is_hired'    => $trip->is_hired,
                'km'          => $trip->km,
                'km_month'    => $part['km'],
                'hours'       => $part['hours'],
                'parts'       => $item['count'],
                'split'       => $item['count'] > 1,
                'running'     => $trip->status === 'on_road',
                'ga_share'    => $part['frozen'] ? $part['ga_share'] : ($rate !== null ? round($part['km'] * $rate, 2) : null),
                'direct'      => $direct,
                'true_profit' => $true['true_profit'],
                'final'       => $true['final'],
            ];
        }

        $figures = $closed
            ? ['revenue' => (float) $row->revenue, 'direct_profit' => (float) $row->direct_profit, 'true_profit' => (float) $row->true_profit, 'trips' => (int) $row->trips_count]
            : $this->monthFigures($month, $gaTotal);

        $blockers = [];
        if (! $closed) {
            if (Carbon::now()->lessThan($month->copy()->addMonth())) {
                $blockers[] = 'not_ended';
            }
            if ($gaTotal <= 0) {
                $blockers[] = 'no_ga';
            }
            if ($km <= 0) {
                $blockers[] = 'no_km';
            }
        }

        return [
            'month'      => $key,
            'status'     => $closed ? 'closed' : ($row ? 'reopened' : 'open'),
            'closed'     => $closed,
            'lines'      => $this->lines($month),
            'ga_total'   => $gaTotal,
            'km'         => $km,
            'rate'       => $rate,
            'rule'       => $rule,
            'basis'      => $includeHired ? 'all_km' : 'own_km',
            'figures'    => $figures,
            'rows'       => $rows,
            'running'    => collect($rows)->where('running', true)->count(),
            'split'      => collect($rows)->where('split', true)->count(),
            'blockers'   => $blockers,
            'can_close'  => ! $closed && $blockers === [],
            'closed_by'  => $closed ? $row->closer?->name : null,
            'closed_at'  => $closed ? $row->closed_at?->toIso8601String() : null,
            'reopen'     => $row && $row->reopen_count > 0 ? [
                'count'  => $row->reopen_count,
                'reason' => $row->reopen_reason,
                'at'     => $row->reopened_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** Revenue, direct profit and true profit of the trips delivered in the month. */
    private function monthFigures(Carbon $month, float $gaTotal): array
    {
        $start = $month->format('Y-m-d H:i:s');
        $end = $month->copy()->addMonth()->format('Y-m-d H:i:s');

        $ids = Trip::query()->whereIn('trips.status', ['delivered', 'settled'])
            ->where('trips.delivered_at', '>=', $start)->where('trips.delivered_at', '<', $end)->select('trips.id');

        $freight = (float) Trip::query()->whereIn('trips.id', (clone $ids))->sum('trips.freight_price');
        $charges = (float) TripCharge::query()->whereIn('trip_id', (clone $ids))
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'deduction' THEN -amount ELSE amount END), 0) as t")->value('t');
        $cost = (float) TripExpense::query()->whereIn('trip_id', (clone $ids))->where('is_personal', false)->sum('amount');

        $revenue = round($freight + $charges, 2);
        $direct = round($revenue - $cost, 2);

        return [
            'revenue'       => $revenue,
            'direct_profit' => $direct,
            'true_profit'   => round($direct - $gaTotal, 2),
            'trips'         => Trip::query()->whereIn('trips.id', (clone $ids))->count(),
        ];
    }

    // ── Closing and re-opening ─────────────────────────────────────

    public function close(Carbon $month, User $user): MonthClose
    {
        $companyId = $user->company_id;

        try {
            return DB::transaction(function () use ($month, $user, $companyId) {
                $existing = MonthClose::query()->where('month', self::dbMonth($month))->lockForUpdate()->first();

                if ($existing && $existing->isClosed()) {
                    throw TripRuleException::because('finance.close.already_closed');
                }

                $summary = $this->summary($month, $companyId);

                if (in_array('not_ended', $summary['blockers'], true)) {
                    throw TripRuleException::because('finance.close.not_ended');
                }
                if (in_array('no_ga', $summary['blockers'], true)) {
                    throw TripRuleException::because('finance.close.no_ga');
                }
                if (in_array('no_km', $summary['blockers'], true)) {
                    throw TripRuleException::because('finance.close.no_km');
                }

                $rate = (float) $summary['rate'];
                $key = self::monthKey($month);

                TripAllocation::query()->where('month', self::dbMonth($month))->delete();

                $now = Carbon::now();
                $trips = Trip::query()->whereIn('trips.id', array_column($summary['rows'], 'id'))->with('allocations')->get()->keyBy('id');
                foreach ($summary['rows'] as $r) {
                    $trip = $trips[$r['id']];
                    $span = Allocator::span($trip, $now);

                    TripAllocation::query()->create([
                        'company_id'  => $companyId,
                        'trip_id'     => $trip->id,
                        'month'       => self::dbMonth($month),
                        'km'          => $r['km_month'],
                        'hours'       => $r['hours'],
                        'rate'        => $rate,
                        'ga_share'    => round($r['km_month'] * $rate, 2),
                        'is_estimate' => (bool) ($span['estimated'] ?? false),
                    ]);
                }

                $data = [
                    'company_id'    => $companyId,
                    'month'         => self::dbMonth($month),
                    'status'        => 'closed',
                    'ga_total'      => $summary['ga_total'],
                    'km'            => $summary['km'],
                    'rate'          => $rate,
                    'trips_count'   => $summary['figures']['trips'],
                    'revenue'       => $summary['figures']['revenue'],
                    'direct_profit' => $summary['figures']['direct_profit'],
                    'true_profit'   => $summary['figures']['true_profit'],
                    'split_rule'    => $summary['rule'],
                    'basis'         => $summary['basis'],
                    'closed_by'     => $user->id,
                    'closed_at'     => $now,
                ];

                $row = $existing ?? new MonthClose();
                $row->fill($data)->save();

                Audit::record('month.closed', $row, ['after' => [
                    'month' => $key, 'ga_total' => $row->ga_total, 'km' => $row->km, 'rate' => $row->rate, 'trips' => count($summary['rows']),
                ]]);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            // Two people pressed Close at the same moment: the other one won.
            throw TripRuleException::because('finance.close.already_closed');
        }
    }

    /** Opens a closed month again (permission month_close.reopen, checked by the route). */
    public function reopen(Carbon $month, string $reason, User $user): MonthClose
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw TripRuleException::because('finance.close.reason_required');
        }

        return DB::transaction(function () use ($month, $reason, $user) {
            $row = MonthClose::query()->where('month', self::dbMonth($month))->lockForUpdate()->first();

            if (! $row || ! $row->isClosed()) {
                throw TripRuleException::because('finance.close.not_closed');
            }

            TripAllocation::query()->where('month', self::dbMonth($month))->delete();

            $row->fill([
                'status'        => 'open',
                'reopened_by'   => $user->id,
                'reopened_at'   => Carbon::now(),
                'reopen_reason' => mb_substr($reason, 0, 250),
                'reopen_count'  => $row->reopen_count + 1,
            ])->save();

            Audit::record('month.reopened', $row, ['month' => self::monthKey($month), 'reason' => $reason, 'was_rate' => $row->rate]);

            return $row;
        });
    }
}
