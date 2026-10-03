{{-- El Tara — Account activation email (plain-text twin of emails/activate-account.blade.php) --}}
@php $lang = $locale ?? app()->getLocale(); @endphp
{{ __('emails.activate.heading', [], $lang) }}

{{ __('emails.activate.greeting', ['name' => $user->name], $lang) }}

{{ __('emails.activate.intro', ['company' => $companyName], $lang) }}

{{ $url }}

{{ trans_choice('emails.activate.expire', $expireDays, [], $lang) }}

{{ __('emails.activate.closing', [], $lang) }}

--
{{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}
