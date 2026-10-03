{{-- El Tara — shared email layout (resources/views/emails/layout.blade.php). Every email extends it. --}}
@php
    /*
    | Every style in these emails is INLINE. That is a correctness
    | requirement, not a preference — see App\Support\EmailStyles for
    | what happens when it is not, and why the symptom looks like one
    | misplaced line rather than a missing stylesheet.
    |
    | $s is shared into every emails.* view by AppServiceProvider
    | rather than built here, because Blade renders a @section BEFORE
    | the layout that wraps it: a variable declared in this file would
    | not be visible to the content templates, and two copies of a
    | design drift apart.
    |
    | Printed with {!! !!} rather than {{ }}: these are hardcoded CSS
    | constants from EmailStyles, never user data, and escaping them
    | turns the quotes in a font stack into &#039; inside the CSS.
    */
    $lang = $locale ?? app()->getLocale();
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{!! $s['dir'] !!}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    {{-- Designed for a light background: asks Apple Mail and others not
         to recolour it in dark mode (which darkens the white card but
         not the colours set on the text inside it). --}}
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject ?? config('app.name') }}</title>

    {{-- No web fonts are loaded from the internet: a font link makes the reader's mail app contact Google
         every time the email is opened (it tells Google that, and when, this person opened an El Tara email).
         The system fonts named in EmailStyles are used instead; they cover Arabic and English well. --}}

    {{-- A bonus for clients that keep it, never the only copy.
         Everything here is already applied inline; this block exists
         for the one thing an inline style cannot express — the phone
         breakpoint. If a client strips it the email still looks
         right, which is the whole point. --}}
    <style>
        @media only screen and (max-width: 600px) {
            .m-body   { padding: 24px 20px !important; }
            .m-header { padding: 24px 20px !important; }
            .m-code   { font-size: 26px !important; letter-spacing: 0.2em !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; width:100% !important; background-color:#F4F6F9; -webkit-text-size-adjust:100%;">
{{-- What the inbox list shows under the subject; without it, the list
     shows the first text of the email ("ت El Tara التارة …"). --}}
@hasSection('preheader')
<div style="{!! $s['preheader'] !!}">@yield('preheader')</div>
@endif
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="{!! $s['wrapper'] !!}">
    <tr>
        <td align="center" style="padding:0;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="{!! $s['container'] !!}">
                <tr>
                    <td class="m-header" style="{!! $s['header'] !!}">
                        {{-- The brand mark and name as in the app's top bar,
                             in HTML (no image to block or break). --}}
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="{!! $s['markTable'] !!}">
                            <tr><td width="44" height="44" align="center" valign="middle" style="{!! $s['mark'] !!}">ت</td></tr>
                        </table>
                        <p style="{!! $s['brand'] !!}">El Tara <span style="{!! $s['brandAlt'] !!}">التارة</span></p>
                    </td>
                </tr>
                <tr><td style="{!! $s['accent'] !!}">&nbsp;</td></tr>
                <tr>
                    <td class="m-body" style="{!! $s['body'] !!}">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="{!! $s['footer'] !!}">
                        <p style="{!! $s['footerP'] !!}">© {{ date('Y') }} {{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
