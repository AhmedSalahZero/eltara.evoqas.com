{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Printable dashboard ( /office/dashboard/print )
     Location: resources/views/office/dashboard-print.blade.php

     Scope §6.1 "Export": the dashboard's figures on paper (Save as PDF
     from the print window). Controller: Office\DashboardController@print.
     ═══════════════════════════════════════════════════════════════ --}}
@php
    $ar = $locale === 'ar';
    $l = fn (string $a, string $e) => $ar ? $a : $e;
    $m = fn ($n, $d = 0) => $n === null ? '—' : number_format((float) $n, $d);
    $k = $dash['kpis'];
    $period = ['m' => $l('هذا الشهر حتى اليوم', 'This month to date'), 'lm' => $l('الشهر الماضي', 'Last month'), 'q' => $l('آخر 3 شهور', 'Last 3 months'), 'y' => $l('آخر 12 شهر', 'Last 12 months')][$dash['period']];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $l('لوحة التحكم', 'Dashboard') }} · {{ $company }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Tajawal, 'Segoe UI', Tahoma, Arial, sans-serif; color: #123055; margin: 0; font-size: 12.5px; }
        .page { max-width: 800px; margin: 0 auto; padding: 16px; }
        header { border-bottom: 3px solid #1B4F8C; padding-bottom: 8px; margin-bottom: 10px; }
        header .co { font-size: 14px; font-weight: 700; } header h1 { margin: 2px 0 0; font-size: 21px; }
        h2 { font-size: 14px; margin: 16px 0 6px; color: #1B4F8C; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap: 8px; }
        .tiles div { border: 1px solid #D7E6F2; border-radius: 8px; padding: 7px 10px; }
        .tiles span { display: block; font-size: 10.5px; color: #5C7999; } .tiles b { font-size: 15px; }
        .num { font-family: Inter, Arial, sans-serif; direction: ltr; unicode-bidi: isolate; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #D7E6F2; padding: 4px 7px; text-align: start; } th { background: #EFF6FC; font-size: 11px; }
        td.e, th.e { text-align: end; } .rd { color: #C0392B; } .gn { color: #1E7A5C; }
        .foot { margin-top: 14px; font-size: 10.5px; color: #5C7999; }
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
        <h1>{{ $l('لوحة التحكم', 'Dashboard') }} — {{ $period }}</h1>
        <div>{{ $dash['from'] }} → {{ $dash['to'] }}</div>
    </header>

    <div class="tiles">
        <div><span>{{ $l('الإيرادات', 'Revenue') }}</span><b class="num">{{ $m($k['rev']['value']) }}</b></div>
        <div><span>{{ $l('التكاليف المباشرة', 'Direct costs') }}</span><b class="num">{{ $m($k['direct']['value']) }}</b></div>
        <div><span>{{ $l('الربح المباشر', 'Direct profit') }}</span><b class="num">{{ $m($k['dp']['value']) }}</b></div>
        <div><span>{{ $l('الربح الحقيقي', 'True profit') }}{{ $k['tp']['estimate'] ? ' ('.$l('تقديري', 'estimate').')' : '' }}</span><b class="num">{{ $m($k['tp']['value']) }}</b></div>
        <div><span>{{ $l('الكيلومترات', 'Kilometres') }}</span><b class="num">{{ $m($k['km']['value']) }}</b></div>
        <div><span>{{ $l('الإيراد لكل كم', 'Revenue per km') }}</span><b class="num">{{ $m($k['rev_km'], 2) }}</b></div>
        <div><span>{{ $l('التكلفة المباشرة لكل كم', 'Direct cost per km') }}</span><b class="num">{{ $m($k['cost_km'], 2) }}</b></div>
        <div><span>{{ $l('المصروفات العامة لكل كم', 'G&A per km') }}</span><b class="num">{{ $m($k['ga_km'], 2) }}</b></div>
        <div><span>{{ $l('تشغيل الأسطول', 'Fleet utilisation') }}</span><b class="num">{{ $m($k['util']) }}%</b></div>
        <div><span>{{ $l('متوسط كم/لتر', 'Average km/L') }}</span><b class="num">{{ $m($k['kmpl'], 2) }}</b></div>
        <div><span>{{ $l('نقدية خارج الخزينة', 'Cash outside the safe') }}</span><b class="num">{{ $m($dash['cash']['total']) }}</b></div>
        <div><span>{{ $l('رحلات على الطريق', 'Trips on the road') }}</span><b class="num">{{ $dash['road']['active'] }}</b></div>
    </div>

    <h2>{{ $l('الإيرادات والأرباح — 12 شهر', 'Revenue and profit — 12 months') }}</h2>
    <table>
        <thead><tr><th>{{ $l('الشهر', 'Month') }}</th><th class="e">{{ $l('الإيراد', 'Revenue') }}</th><th class="e">{{ $l('تكاليف مباشرة', 'Direct costs') }}</th><th class="e">{{ $l('ربح مباشر', 'Direct profit') }}</th><th class="e">{{ $l('ربح حقيقي', 'True profit') }}</th><th class="e">{{ $l('كم', 'km') }}</th></tr></thead>
        <tbody>
        @foreach ($dash['history'] as $h)
            <tr><td class="num">{{ $h['key'] }}</td><td class="e num">{{ $m($h['rev']) }}</td><td class="e num">{{ $m($h['direct']) }}</td><td class="e num">{{ $m($h['dp']) }}</td>
                <td class="e num">{{ $m($h['tp']) }}{{ $h['closed'] ? '' : ' *' }}</td><td class="e num">{{ $m($h['km']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="foot">* {{ $l('تقديري حتى إقفال الشهر', 'Estimate until the month is closed') }}</div>

    <h2>{{ $l('مركز التنبيهات', 'Action centre') }}</h2>
    <table><tbody>
        @foreach ($dash['actions'] as $a)
            <tr><td>{{ __('dashboard.actions.'.$a['key']) }}</td><td class="e num">{{ $a['count'] }}</td></tr>
        @endforeach
    </tbody></table>

    @if (count($dash['customers']))
        <h2>{{ $l('العملاء الأكثر ربحية', 'Most profitable customers') }}</h2>
        <table>
            <thead><tr><th>{{ $l('العميل', 'Customer') }}</th><th class="e">{{ $l('الإيراد', 'Revenue') }}</th><th class="e">{{ $l('التكلفة', 'Cost') }}</th><th class="e">{{ $l('الربح', 'Profit') }}</th></tr></thead>
            <tbody>@foreach ($dash['customers'] as $c)<tr><td>{{ $c['name'] }}</td><td class="e num">{{ $m($c['rev']) }}</td><td class="e num">{{ $m($c['cost']) }}</td><td class="e num">{{ $m($c['profit']) }}</td></tr>@endforeach</tbody>
        </table>
    @endif

    @if (count($dash['drivers']))
        <h2>{{ $l('أداء السائقين', 'Driver scorecard') }}</h2>
        <table>
            <thead><tr><th>{{ $l('السائق', 'Driver') }}</th><th class="e">{{ $l('رحلات', 'Trips') }}</th><th class="e">{{ $l('كم', 'km') }}</th><th class="e">{{ $l('ربح مباشر', 'Direct profit') }}</th><th class="e">{{ $l('فرق الميزانية %', 'Budget var. %') }}</th></tr></thead>
            <tbody>@foreach ($dash['drivers'] as $g)<tr><td>{{ $g['name'] }}</td><td class="e num">{{ $g['trips'] }}</td><td class="e num">{{ $m($g['km']) }}</td><td class="e num">{{ $m($g['profit']) }}</td><td class="e num">{{ $m($g['var'], 1) }}</td></tr>@endforeach</tbody>
        </table>
    @endif

    <div class="foot">{{ $l('طُبع بواسطة', 'Printed by') }} {{ $by }} · {{ now()->format('Y-m-d H:i') }}</div>
</div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
</body>
</html>
