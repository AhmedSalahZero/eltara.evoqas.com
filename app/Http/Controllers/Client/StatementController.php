<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClient;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripCollection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Client\StatementController ( /client/statement )
//  Location: app/Http/Controllers/Client/StatementController.php
//
//  Scope §7 "Statement & invoices": what the client owes for his
//  delivered trips: total of delivered trips, every trip with its
//  price, the cash he handed to drivers; export to Excel and a
//  printable page (Save as PDF). Invoice numbers, "invoiced vs not
//  yet invoiced" and the list of invoices come with Step 6 (invoice
//  linking) — until then every delivered trip is shown as not yet
//  invoiced.
// ══════════════════════════════════════════════════════════════════

class StatementController extends Controller
{
    use ServesClient;

    public function index(Request $request): Response
    {
        [$rows, $totals, $range] = $this->data($request);

        return Inertia::render('Client/Statement', ['trips' => $rows, 'totals' => $totals, 'filters' => $range]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$rows, $totals] = $this->data($request);
        $ar = app()->getLocale() === 'ar';

        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setRightToLeft($ar);
        $sheet->setTitle($ar ? 'كشف الحساب' : 'Statement');
        $sheet->fromArray($ar ? ['رقم الرحلة', 'التاريخ', 'المسار', 'الشاحنة', 'الحالة', 'رقم الفاتورة', 'السعر'] : ['Trip', 'Date', 'Route', 'Truck', 'Status', 'Invoice', 'Price'], null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        $r = 2;
        foreach ($rows as $t) {
            $sheet->fromArray([
                $t['number'], substr((string) $t['loading_at'], 0, 10), $t['route'],
                $t['vehicle'] ? $t['vehicle']['number'].' '.$t['vehicle']['letters'] : '', __('trips.status.'.$t['status']), $t['invoice'] ?? '', $t['price'],
            ], null, "A{$r}");
            $r++;
        }
        $sheet->setCellValue("F{$r}", $ar ? 'إجمالي المُسلَّم' : 'Delivered total');
        $sheet->setCellValue("G{$r}", $totals['delivered']);
        $sheet->setCellValue('F'.($r + 1), $ar ? 'منه مفوتر' : 'Invoiced');
        $sheet->setCellValue('G'.($r + 1), $totals['invoiced']);
        $sheet->setCellValue('F'.($r + 2), $ar ? 'منه غير مفوتر' : 'Not yet invoiced');
        $sheet->setCellValue('G'.($r + 2), $totals['not_invoiced']);
        $sheet->getStyle("F{$r}:G".($r + 2))->getFont()->setBold(true);
        $sheet->getStyle('G2:G'.($r + 2))->getNumberFormat()->setFormatCode('#,##0.00');
        if ($totals['truncated']) {
            $sheet->setCellValue('A'.($r + 4), $ar ? 'يعرض أحدث 5,000 رحلة فقط — ضيّق الفترة.' : 'Showing the latest 5,000 trips only — narrow the period.');
            $sheet->getStyle('A'.($r + 4))->getFont()->setBold(true);
        }
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($sheet->getParent()))->save('php://output'),
            'el-tara-statement-'.now()->format('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function print(Request $request): \Illuminate\Contracts\View\View
    {
        [$rows, $totals, $range] = $this->data($request);
        $client = $this->client($request)->loadMissing('company', 'customer');

        return view('client.statement', [
            'company' => $client->company->displayName(), 'customer' => $client->customer->displayName(),
            'trips' => $rows, 'totals' => $totals, 'range' => $range, 'locale' => app()->getLocale(),
        ]);
    }

    /** @return array{0: list<array>, 1: array, 2: array} */
    private function data(Request $request): array
    {
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $trips = $this->withRowData($this->myTrips($request)
            ->when($from, fn ($q) => $q->whereDate('trips.loading_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('trips.loading_at', '<=', $to)))
            ->orderByDesc('trips.loading_at')->limit(5001)->get();

        // One row more than the cap = the statement is incomplete: say so (never silently drop trips).
        $truncated = $trips->count() > 5000;
        $trips = $trips->take(5000)->values();

        $rows = $trips->map(fn (Trip $t) => $this->tripRow($t) + [])->values()->all();
        $delivered = collect($rows)->whereIn('status', ['delivered', 'settled'])->sum('price');
        $invoiced = collect($rows)->whereIn('status', ['delivered', 'settled'])->whereNotNull('invoice')->sum('price');
        $onTheWay = collect($rows)->whereIn('status', ['planned', 'accepted', 'loading', 'on_road'])->sum('price');

        $cash = TripCollection::query()->where('customer_id', $this->client($request)->customer_id)
            ->when($from, fn ($q) => $q->whereDate('received_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('received_at', '<=', $to))
            ->whereNull('resolution')->sum('amount');

        return [$rows, [
            'delivered' => round((float) $delivered, 2),
            'invoiced'  => round((float) $invoiced, 2),
            'not_invoiced' => round((float) $delivered - (float) $invoiced, 2),
            'in_progress' => round((float) $onTheWay, 2),
            'cash'      => round((float) $cash, 2),
            'count'     => count($rows),
            'truncated' => $truncated,
        ], ['from' => $from, 'to' => $to]];
    }
}
