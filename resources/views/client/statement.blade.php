{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Printable client statement ( /client/statement/print )
     Location: resources/views/client/statement.blade.php

     Scope §7 "Statement & invoices": the client's delivered trips and
     their prices, printable (Save as PDF from the print window).
     Shows only what the client may see — never costs or profit.
     Controller: App\Http\Controllers\Client\StatementController@print.
     ═══════════════════════════════════════════════════════════════ --}}
@php
    $ar = $locale === 'ar';
    $l = fn (string $a, string $e) => $ar ? $a : $e;
    $fmt = fn ($n) => number_format((float) $n, 2);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $l('كشف حساب', 'Statement') }} · {{ $customer }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: Tajawal, 'Segoe UI', Tahoma, Arial, sans-serif; color: #123055; margin: 0; font-size: 13px; }
        .page { max-width: 780px; margin: 0 auto; padding: 18px; }
        header { border-bottom: 3px solid #1B4F8C; padding-bottom: 10px; }
        header .co { font-size: 15px; font-weight: 700; }
        header h1 { margin: 2px 0 0; font-size: 22px; }
        .sum { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin: 14px 0; }
        .sum div { border: 1px solid #D7E6F2; border-radius: 8px; padding: 8px 10px; }
        .sum span { display: block; font-size: 11px; color: #5C7999; }
        .sum b { font-size: 16px; }
        .num { font-family: Inter, Arial, sans-serif; direction: ltr; unicode-bidi: isolate; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #D7E6F2; padding: 6px 8px; text-align: start; }
        th { background: #EFF6FC; font-size: 12px; }
        td.e, th.e { text-align: end; }
        .noprint { text-align: center; margin: 12px 0; }
        .noprint button { font: inherit; padding: 8px 18px; border-radius: 8px; border: 1px solid #1B4F8C; background: #1B4F8C; color: #fff; cursor: pointer; }
        @media print { .noprint { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
<div class="noprint"><button type="button" data-print>{{ $l('طباعة / حفظ PDF', 'Print / Save as PDF') }}</button></div>
<div class="page">
    <header>
        <div class="co">{{ $company }}</div>
        <h1>{{ $l('كشف حساب', 'Statement') }} — {{ $customer }}</h1>
        <div>{{ $range['from'] ?? '…' }} → {{ $range['to'] ?? now()->format('Y-m-d') }}</div>
    </header>

    <div class="sum">
        <div><span>{{ $l('إجمالي الرحلات المُسلَّمة', 'Delivered trips total') }}</span><b class="num">{{ $fmt($totals['delivered']) }} EGP</b></div>
        <div><span>{{ $l('منه مفوتر', 'Invoiced') }}</span><b class="num">{{ $fmt($totals['invoiced']) }} EGP</b></div>
        <div><span>{{ $l('منه غير مفوتر', 'Not yet invoiced') }}</span><b class="num">{{ $fmt($totals['not_invoiced']) }} EGP</b></div>
        <div><span>{{ $l('رحلات جارية / مخططة', 'Planned / running') }}</span><b class="num">{{ $fmt($totals['in_progress']) }} EGP</b></div>
        <div><span>{{ $l('نقد سلّمته للسائقين', 'Cash handed to drivers') }}</span><b class="num">{{ $fmt($totals['cash']) }} EGP</b></div>
    </div>

    @if (!empty($totals['truncated']))<p style="font-weight:700;color:#C0392B">{{ $l('يعرض أحدث 5,000 رحلة فقط، لذلك الإجماليات غير كاملة — ضيّق الفترة.', 'Showing the latest 5,000 trips only, so these totals are incomplete — narrow the period.') }}</p>@endif

    <table>
        <thead><tr>
            <th>{{ $l('الرحلة', 'Trip') }}</th><th>{{ $l('التاريخ', 'Date') }}</th><th>{{ $l('المسار', 'Route') }}</th>
            <th>{{ $l('الحالة', 'Status') }}</th><th>{{ $l('الفاتورة', 'Invoice') }}</th><th class="e">{{ $l('السعر', 'Price') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($trips as $t)
            <tr>
                <td class="num">{{ $t['number'] }}</td>
                <td class="num">{{ substr((string) $t['loading_at'], 0, 10) }}</td>
                <td>{{ $t['route'] }}</td>
                <td>{{ __('trips.status.'.$t['status']) }}</td>
                <td class="num">{{ $t['invoice'] ?? '—' }}</td>
                <td class="e num">{{ $fmt($t['price']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
</body>
</html>
