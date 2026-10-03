{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Printable trip order ( /office/trips/{id}/print )
     Location: resources/views/trips/print.blade.php

     Scope §6.3 "Documents: printable trip order". One A4 page the
     office prints and hands to the driver: trip number, route,
     customer, truck, driver, loading time, cargo, the custody handed
     over and what it is meant for (the route's standard budget), and
     signature boxes. It shows NO prices or profits — the driver never
     sees them (Scope §8.1). Opens the browser's print window by itself.
     Controller: App\Http\Controllers\Office\TripController@print.
     ═══════════════════════════════════════════════════════════════ --}}
@php
    $ar = $locale === 'ar';
    $l = fn (string $a, string $e) => $ar ? $a : $e;
    $fmt = fn ($n) => number_format((float) $n, 0);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $trip->number }} · {{ $l('أمر تشغيل', 'Trip order') }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: Tajawal, 'Segoe UI', Tahoma, Arial, sans-serif; color: #123055; margin: 0; font-size: 13px; }
        .page { max-width: 780px; margin: 0 auto; padding: 18px; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1B4F8C; padding-bottom: 12px; }
        header h1 { margin: 0; font-size: 22px; }
        header .co { font-size: 15px; font-weight: 700; }
        header .no { font-family: Inter, Arial, sans-serif; font-size: 20px; font-weight: 800; color: #1B4F8C; direction: ltr; }
        .route { font-size: 18px; font-weight: 800; margin: 14px 0 4px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 12px; }
        .grid div { border: 1px solid #D7E6F2; border-radius: 8px; padding: 8px 10px; }
        .grid span { display: block; font-size: 11px; color: #5C7999; }
        .grid b { font-size: 14px; }
        .num { font-family: Inter, Arial, sans-serif; direction: ltr; unicode-bidi: isolate; }
        h2 { font-size: 14px; margin: 20px 0 8px; border-bottom: 1px dashed #D7E6F2; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #D7E6F2; padding: 6px 8px; text-align: start; }
        th { background: #EFF6FC; font-size: 12px; }
        td.e, th.e { text-align: end; }
        .custody { font-size: 20px; font-weight: 800; }
        .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 34px; }
        .sign div { border-top: 1px solid #123055; padding-top: 6px; text-align: center; font-size: 12px; min-height: 60px; }
        .foot { margin-top: 24px; font-size: 11px; color: #5C7999; text-align: center; }
        .noprint { text-align: center; margin: 12px 0; }
        .noprint button { font: inherit; padding: 8px 18px; border-radius: 8px; border: 1px solid #1B4F8C; background: #1B4F8C; color: #fff; cursor: pointer; }
        @media print { .noprint { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
<div class="noprint"><button type="button" data-print>{{ $l('طباعة', 'Print') }}</button></div>
<div class="page">
    <header>
        <div>
            <div class="co">{{ $trip->company->displayName($locale) }}</div>
            <h1>{{ $l('أمر تشغيل رحلة', 'Trip order') }}</h1>
        </div>
        <div class="no">{{ $trip->number }}</div>
    </header>

    <div class="route">{{ $trip->route?->displayName($locale) }}</div>
    <div>{{ $l('ذهاب وعودة', 'Round trip') }} · <span class="num">{{ $fmt($trip->km) }} km</span>@if($trip->planned_hours) · {{ $l('المدة المعتادة', 'Usual time') }} <span class="num">{{ rtrim(rtrim(number_format($trip->planned_hours, 1), '0'), '.') }} h</span>@endif</div>

    <div class="grid">
        <div><span>{{ $l('العميل', 'Customer') }}</span><b>{{ $trip->customer?->displayName($locale) }}</b></div>
        <div><span>{{ $l('الشاحنة', 'Truck') }}</span><b class="num">{{ $trip->vehicle?->plateText() }}</b>@if($trip->is_hired) <span>{{ $l('مؤجرة', 'Hired') }} — {{ $trip->vehicle?->owner_name }}</span>@endif</div>
        <div><span>{{ $l('السائق', 'Driver') }}</span><b>{{ $trip->driver?->name ?? '—' }}</b>@if($trip->driver)<span class="num">{{ $trip->driver->mobile }}</span>@endif</div>
        <div><span>{{ $l('موعد التحميل', 'Loading') }}</span><b class="num">{{ $trip->loading_at?->format('d/m/Y H:i') }}</b></div>
        <div><span>{{ $l('البضاعة', 'Cargo') }}</span><b>{{ $trip->cargoType?->displayName($locale) ?: '—' }}</b></div>
        <div><span>{{ $l('الحمولة (طن)', 'Weight (tons)') }}</span><b class="num">{{ $trip->weight_tons !== null ? rtrim(rtrim(number_format($trip->weight_tons, 2), '0'), '.') : '—' }}</b></div>
        <div><span>{{ $l('العميل يدفع نقدية للسائق', 'Client pays the driver cash') }}</span><b>{{ $trip->client_pays_cash ? $l('نعم — تُسجل وتُؤكد من الطرفين', 'Yes — recorded and confirmed by both sides') : $l('لا', 'No') }}</b></div>
    </div>

    @unless($trip->is_hired)
        <h2>{{ $l('العهدة', 'Custody') }}</h2>
        <table>
            <tr>
                <th>{{ $l('العهدة المخططة', 'Planned custody') }}</th>
                <th>{{ $l('المسلَّم للسائق', 'Handed to the driver') }}</th>
            </tr>
            <tr>
                <td class="custody num">{{ $fmt($trip->custody_planned) }}</td>
                <td class="custody num">{{ $custody > 0 ? $fmt($custody) : '' }}</td>
            </tr>
        </table>

        @if($budget->isNotEmpty())
            <h2>{{ $l('بنود المصروفات المعتادة لهذا المسار', 'Usual road costs on this route') }}</h2>
            <table>
                <tr><th>{{ $l('البند', 'Category') }}</th><th class="e">{{ $l('المعتاد (جنيه)', 'Usual (EGP)') }}</th></tr>
                @foreach($budget as $line)
                    <tr><td>{{ $line['name'] }}</td><td class="e num">{{ $fmt($line['amount']) }}</td></tr>
                @endforeach
            </table>
        @endif
        <p style="font-size:12px;color:#5C7999">{{ $l('كل مصروف نقدي يحتاج صورة الإيصال. المصروف الشخصي من العهدة يُسجل سلفة ويُخصم من المرتب.', 'Every cash expense needs a receipt photo. Personal spending from custody is recorded as an advance, deducted from salary.') }}</p>
    @endunless

    @if($trip->notes)
        <h2>{{ $l('ملاحظات', 'Notes') }}</h2>
        <p style="white-space:pre-line">{{ $trip->notes }}</p>
    @endif

    <div class="sign">
        <div>{{ $l('المكتب', 'Office') }}</div>
        <div>{{ $l('الخزينة (تسليم العهدة)', 'Treasury (custody handed over)') }}</div>
        <div>{{ $l('السائق', 'Driver') }}</div>
    </div>

    <div class="foot">{{ $l('التارة — كل مشوار محسوب', 'El Tara — every trip counted') }} · <span class="num">{{ now()->format('d/m/Y H:i') }}</span></div>
</div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
</body>
</html>
