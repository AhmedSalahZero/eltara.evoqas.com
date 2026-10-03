{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Subscription ending email (HTML)
     Location: resources/views/emails/subscription-ending.blade.php
     Sent by App\Notifications\SubscriptionEndingNotification.
     Strings: lang/*/emails.php → subscription_ending.*
     ═══════════════════════════════════════════════════════════════ --}}
@extends('emails.layout')
@php
    $lang = $locale ?? app()->getLocale();
    // "in 14 days" / "خلال 14 يومًا", "خلال يومين"…: the number decides the wording (lang/*/emails.php).
    $intro = trans_choice('emails.subscription_ending.intro', $daysLeft, ['company' => $companyName, 'days' => $daysLeft], $lang);
@endphp

@section('preheader', $intro)

@section('content')

<h1 style="{!! $s['h1'] !!}">{{ __('emails.subscription_ending.heading', [], $lang) }}</h1>

<p style="{!! $s['p'] !!}">{{ __('emails.subscription_ending.greeting', ['name' => $user->name], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ $intro }}</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="{!! $s['codeBox'] !!}">
            {{-- A date, so it reads left-to-right in both languages. --}}
            <span class="m-code" dir="ltr" style="{!! $s['date'] !!}">{{ $endsOn }}</span>
        </td>
    </tr>
</table>

{{-- The caption for the date above it, so it is centred with the box
     rather than aligned to the body text. --}}
<p style="{!! $s['caption'] !!}">
    {{ __('emails.subscription_ending.ends_on_label', [], $lang) }}
</p>

<p style="{!! $s['p'] !!}">{{ __('emails.subscription_ending.what_happens', [], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.subscription_ending.how_to_renew', [], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.subscription_ending.closing', [], $lang) }}</p>
@endsection
