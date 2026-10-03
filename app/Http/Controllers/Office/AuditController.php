<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Office\AuditController ( /office/audit )   Scope §12
//  Location: app/Http/Controllers/Office/AuditController.php
//
//  The audit log screen: who did what and when — every approval,
//  settlement, price change, month close / re-open and permission
//  change (and more). COMPANY ADMIN ONLY (the audit log is not a
//  permission a normal office user can be given). Read-only: the rows
//  can never be edited or deleted (App\Models\AuditLog). Filters:
//  period, person, area (trips, wallets, …), a word search; each row
//  opens to show what changed (before → after). Excel export of the
//  same filter. AuditLog has no company scope, so the company is
//  filtered here, explicitly.
// ══════════════════════════════════════════════════════════════════

class AuditController extends Controller
{
    private const EXPORT_LIMIT = 20000;

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        $filters = $this->filters($request);
        $companyId = $request->user('web')->company_id;

        $logs = $this->query($companyId, $filters)->orderByDesc('id')->paginate(40)->withQueryString()
            ->through(fn (AuditLog $l) => [
                'id' => $l->id, 'at' => $l->created_at?->toIso8601String(), 'actor' => $l->actor_name, 'actor_type' => $l->actor_type,
                'action' => $l->action, 'label' => $this->label($l->action), 'subject_type' => $l->subject_type, 'subject_id' => $l->subject_id,
                'changes' => $l->changes, 'ip' => $l->ip,
            ]);

        $areas = AuditLog::query()->where('company_id', $companyId)->selectRaw("DISTINCT SUBSTR(action, 1, INSTR(action, '.') - 1) as area")->pluck('area')->filter()->sort()->values();

        return Inertia::render('Office/Audit/Index', [
            'logs'    => $logs,
            'filters' => $filters,
            'areas'   => $areas->map(fn ($a) => ['key' => $a, 'label' => $this->area($a)])->all(),
            'actors'  => AuditLog::query()->where('company_id', $companyId)->whereNotNull('actor_name')->distinct()->orderBy('actor_name')->pluck('actor_name')->all(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);
        $companyId = $request->user('web')->company_id;

        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setTitle(__('audit.title'));
        $sheet->setRightToLeft(app()->getLocale() === 'ar');
        foreach (['when', 'who', 'action', 'subject', 'details', 'ip'] as $i => $col) {
            $sheet->setCellValue([$i + 1, 1], __('audit.columns.'.$col));
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $row = 2;
        foreach ($this->query($companyId, $this->filters($request))->orderByDesc('id')->limit(self::EXPORT_LIMIT)->get() as $l) {
            $sheet->setCellValue([1, $row], $l->created_at?->format('Y-m-d H:i:s'));
            $sheet->setCellValue([2, $row], trim(($l->actor_name ?? '—').' ('.$this->word('actors', $l->actor_type).')'));
            $sheet->setCellValue([3, $row], $this->label($l->action));
            $sheet->setCellValue([4, $row], $l->subject_type ? $this->word('subjects', $l->subject_type).' #'.$l->subject_id : '');
            $sheet->setCellValue([5, $row], $l->changes ? json_encode($l->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
            $sheet->setCellValue([6, $row], $l->ip);
            $row++;
        }
        foreach (range(1, 6) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($sheet->getParent()))->save('php://output'),
            'el-tara-audit-'.now()->format('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user('web')?->isCompanyAdmin(), 403);
    }

    /** @return array{from:?string,to:?string,actor:?string,area:?string,q:?string} */
    private function filters(Request $request): array
    {
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
        $text = fn ($v) => ($v = trim((string) $v)) !== '' ? mb_substr($v, 0, 80) : null;

        return [
            'from' => $date($request->query('from')), 'to' => $date($request->query('to')),
            'actor' => $text($request->query('actor')), 'area' => $text($request->query('area')), 'q' => $text($request->query('q')),
        ];
    }

    private function query(int $companyId, array $f): Builder
    {
        return AuditLog::query()->where('company_id', $companyId)
            ->when($f['from'], fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['to'], fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($f['actor'], fn ($q, $v) => $q->where('actor_name', $v))
            ->when($f['area'], fn ($q, $v) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $v).'.%'))
            ->when($f['q'], fn ($q, $v) => $q->where(function ($w) use ($v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
                $w->where('action', 'like', $like)->orWhere('actor_name', 'like', $like)->orWhere('changes', 'like', $like);
            }));
    }

    private function word(string $group, ?string $key): string
    {
        $full = 'audit.'.$group.'.'.$key;

        return Lang::has($full) ? (string) __($full) : (string) $key;
    }

    private function area(string $area): string
    {
        return $this->word('areas', $area);
    }

    /** "trip.price_changed" → "Trip price changed" / "تغيير سعر رحلة". Unknown codes read as their words. */
    private function label(string $action): string
    {
        [$area, $verb] = array_pad(explode('.', $action, 2), 2, '');
        $verbText = Lang::has('audit.verbs.'.$verb) ? __('audit.verbs.'.$verb) : str_replace('_', ' ', $verb);

        return trim(strtr(__('audit.template'), [':area' => $this->area($area) === $area ? str_replace('_', ' ', $area) : $this->area($area), ':verb' => $verbText]));
    }
}
