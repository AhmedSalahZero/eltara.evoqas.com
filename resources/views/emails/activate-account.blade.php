{{-- ═══════════════════════════════════════════════════════════════
     El Tara — Account activation email (HTML)
     Location: resources/views/emails/activate-account.blade.php
     Sent by App\Notifications\ActivateAccountNotification when an
     office account is created. Strings: lang/*/emails.php → activate.*
     ═══════════════════════════════════════════════════════════════ --}}
@extends('emails.layout')
@php $lang = $locale ?? app()->getLocale(); @endphp

@section('preheader', __('emails.activate.intro', ['company' => $companyName], $lang))

@section('content')

<h1 style="{!! $s['h1'] !!}">{{ __('emails.activate.heading', [], $lang) }}</h1>

<p style="{!! $s['p'] !!}">{{ __('emails.activate.greeting', ['name' => $user->name], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.activate.intro', ['company' => $companyName], $lang) }}</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="{!! $s['btnWrap'] !!}">
            <a href="{{ $url }}" style="{!! $s['btn'] !!}">{{ __('emails.activate.button', [], $lang) }}</a>
        </td>
    </tr>
</table>

<p style="{!! $s['muted'] !!}">{{ trans_choice('emails.activate.expire', $expireDays, [], $lang) }}</p>

<p style="{!! $s['muted'] !!}">{{ __('emails.activate.fallback', [], $lang) }}</p>
<p style="{!! $s['link'] !!}"><a href="{{ $url }}" style="{!! $s['link'] !!}">{{ $url }}</a></p>

<p style="{!! $s['p'] !!}">{{ __('emails.activate.closing', [], $lang) }}</p>
@endsection
