{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Printable report ( /office/reports/{report}/print )
     Location: resources/views/office/report-print.blade.php

     Scope §6.14: every report exports Excel and PDF (Save as PDF from
     the print window). Controller: Office\ReportController@print.
     ═══════════════════════════════════════════════════════════════ --}}
@php
    $ar = $locale === 'ar';
    $f = $data['filters'];
    $fmt = function (array $c, $v) {
        if ($v === null || $v === '') return '—';
        return match ($c['type']) {
            'money', 'num' => number_format((float) $v),
            'dec' => number_format((float) $v, 2),
            'pct' => number_format((float) $v, 1).'%',
            default => $v,
        };
    };
    $numeric = fn (array $c) => in_array($c['type'], ['money', 'num', 'dec', 'pct', 'date'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $data['title'] }} · {{ $company }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: Tajawal, 'Segoe UI', Tahoma, Arial, sans-serif; color: #123055; margin: 0; font-size: 11.5px; }
        .page { padding: 14px; }
        header { border-bottom: 3px solid #1B4F8C; padding-bottom: 8px; margin-bottom: 10px; }
        header .co { font-size: 13px; font-weight: 700; } header h1 { margin: 2px 0 0; font-size: 20px; }
        .num { font-family: Inter, Arial, sans-serif; direction: ltr; unicode-bidi: isolate; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #D7E6F2; padding: 4px 6px; text-align: start; vertical-align: top; }
        th { background: #EFF6FC; font-size: 10.5px; } td.e, th.e { text-align: end; }
        tfoot td { font-weight: 700; background: #F6FAFD; } .rd { color: #C0392B; }
        .note { margin: 8px 0; color: #5C7999; } .foot { margin-top: 10px; font-size: 10px; color: #5C7999; }
        .noprint { text-align: center; margin: 12px 0; }
        .noprint button { font: inherit; padding: 8px 18px; border-radius: 8px; border: 1px solid #1B4F8C; background: #1B4F8C; color: #fff; cursor: pointer; }
        thead { display: table-header-group; } tr { page-break-inside: avoid; }
        @media print { .noprint { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
<div class="noprint"><button type="button" data-print>{{ __('reports.print') }}</button></div>
<div class="page">
    <header>
        <div class="co">{{ $company }}</div>
        <h1>{{ $data['title'] }}</h1>
        <div>{{ __('reports.period') }}: <span class="num">{{ $f['from'] }} → {{ $f['to'] }}</span></div>
    </header>
    @if ($data['note'])<div class="note">{{ $data['note'] }}</div>@endif

    <table>
        <thead><tr>@foreach ($data['columns'] as $c)<th class="{{ $numeric($c) ? 'e' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($data['rows'] as $r)
            <tr>@foreach ($data['columns'] as $c)
                <td class="{{ $numeric($c) ? 'e num' : '' }} {{ in_array($c['key'], ['profit', 'true_profit', 'profit_km', 'true_profit_km', 'direct_profit'], true) && (float) ($r[$c['key']] ?? 0) < 0 ? 'rd' : '' }}">{{ $fmt($c, $r[$c['key']] ?? null) }}</td>
            @endforeach</tr>
        @endforeach
        </tbody>
        @if ($data['totals'])
            <tfoot><tr>
                @foreach ($data['columns'] as $i => $c)
                    <td class="{{ $numeric($c) ? 'e num' : '' }}">@if (array_key_exists($c['key'], $data['totals'])){{ $fmt($c, $data['totals'][$c['key']]) }}@elseif ($i === 0){{ __('reports.total') }}@endif</td>
                @endforeach
            </tr></tfoot>
        @endif
    </table>
    @if ($data['truncated'])<div class="note">{{ __('reports.truncated') }}</div>@endif
    <div class="foot">{{ __('reports.printed_by') }} {{ $by }} · {{ now()->format('Y-m-d H:i') }}</div>
</div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
</body>
</html>
