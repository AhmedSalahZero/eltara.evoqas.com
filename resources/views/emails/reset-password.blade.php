{{-- El Tara — Password reset email (HTML). Sent by App\Notifications\ResetPasswordNotification. --}}
@extends('emails.layout')
@php $lang = $locale ?? app()->getLocale(); @endphp

@section('preheader', __('emails.reset_password.intro', [], $lang))

@section('content')

<h1 style="{!! $s['h1'] !!}">{{ __('emails.reset_password.heading', [], $lang) }}</h1>

<p style="{!! $s['p'] !!}">{{ __('emails.reset_password.greeting', ['name' => $user->name], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.reset_password.intro', [], $lang) }}</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="{!! $s['btnWrap'] !!}">
            <a href="{{ $url }}" style="{!! $s['btn'] !!}">{{ __('emails.reset_password.button', [], $lang) }}</a>
        </td>
    </tr>
</table>

<p style="{!! $s['muted'] !!}">{{ trans_choice('emails.reset_password.expire', $expireMinutes, [], $lang) }}</p>

<p style="{!! $s['muted'] !!}">{{ __('emails.reset_password.fallback', [], $lang) }}</p>
<p style="{!! $s['link'] !!}"><a href="{{ $url }}" style="{!! $s['link'] !!}">{{ $url }}</a></p>

<p style="{!! $s['muted'] !!}">{{ __('emails.reset_password.ignore', [], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.reset_password.closing', [], $lang) }}</p>
@endsection
