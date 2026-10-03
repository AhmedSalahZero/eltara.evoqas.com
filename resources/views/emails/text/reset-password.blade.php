{{-- El Tara — Password reset email (plain-text twin). Some mail apps show only text, so every email has one. --}}
@php $lang = $locale ?? app()->getLocale(); @endphp
{{ __('emails.reset_password.heading', [], $lang) }}

{{ __('emails.reset_password.greeting', ['name' => $user->name], $lang) }}

{{ __('emails.reset_password.intro', [], $lang) }}

{{ $url }}

{{ trans_choice('emails.reset_password.expire', $expireMinutes, [], $lang) }}

{{ __('emails.reset_password.ignore', [], $lang) }}

{{ __('emails.reset_password.closing', [], $lang) }}

--
{{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}
