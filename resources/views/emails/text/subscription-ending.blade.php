{{-- El Tara — Subscription ending email (plain-text twin of emails/subscription-ending.blade.php) --}}
@php $lang = $locale ?? app()->getLocale(); @endphp
{{ __('emails.subscription_ending.heading', [], $lang) }}

{{ __('emails.subscription_ending.greeting', ['name' => $user->name], $lang) }}

{{ trans_choice('emails.subscription_ending.intro', $daysLeft, ['company' => $companyName, 'days' => $daysLeft], $lang) }}

{{ __('emails.subscription_ending.ends_on_label', [], $lang) }} {{ $endsOn }}

{{ __('emails.subscription_ending.what_happens', [], $lang) }}

{{ __('emails.subscription_ending.how_to_renew', [], $lang) }}

{{ __('emails.subscription_ending.closing', [], $lang) }}

--
{{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}
