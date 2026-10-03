<?php

namespace App\Providers;

use App\Auth\UnscopedEloquentUserProvider;
use App\Support\EmailStyles;
use App\Support\PasswordRules;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

// ══════════════════════════════════════════════════════════════════
//  El Tara — AppServiceProvider
//  Location: app/Providers/AppServiceProvider.php
//
//  App-wide wiring, in one place:
//    · Permissions — Gate::before() sends every check for a key in
//      config/permissions.php to App\Support\Permissions.
//    · Sign-in lookup for drivers and client users without the
//      company filter ('eloquent_unscoped', see app/Auth).
//    · Rate limits — 'driver-sync' (uploads from the Driver App).
//    · One password policy (App\Support\PasswordRules).
//    · Email styles shared into every emails.* view.
//    · Safety while building — lazy loading and silently dropped
//      attributes throw on your computer, so slow queries (N+1) and
//      typos show up early instead of on the live server.
//    · HTTPS forced on the live server (Scope §12).
// ══════════════════════════════════════════════════════════════════

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ── Permissions ────────────────────────────────────────────
        Gate::before(fn ($user, string $ability) => Permissions::check($user, $ability));

        // ── Sign-in lookup for company-scoped accounts ─────────────
        Auth::provider('eloquent_unscoped', fn ($app, array $config) => new UnscopedEloquentUserProvider($app['hash'], $config['model']));

        // ── Rate limits ────────────────────────────────────────────
        // A phone coming back online may upload several batches in a
        // row; 30 a minute is generous for a person and still stops a
        // runaway loop.
        RateLimiter::for('driver-upload', fn (Request $request) => Limit::perMinute(90)->by('driver-up:'.($request->user('driver')?->id ?? $request->ip())));
        RateLimiter::for('driver-sync', fn (Request $request) => Limit::perMinute(30)->by('driver:'.($request->user('driver')?->id ?? $request->ip())));

        // ── Frontend ───────────────────────────────────────────────
        Vite::prefetch(concurrency: 3);

        // ── Spreadsheets: text such as "=…" can never become a formula (audit Q25) ──
        \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \App\Support\SafeSpreadsheetBinder());

        // ── Models ─────────────────────────────────────────────────
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // ── Security ───────────────────────────────────────────────
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => PasswordRules::defaults());

        // ── Emails ─────────────────────────────────────────────────
        View::composer('emails.*', function ($view) {
            $locale = $view->getData()['locale'] ?? app()->getLocale();

            $view->with('s', EmailStyles::for($locale));
        });
    }
}
