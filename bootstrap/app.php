<?php

use App\Http\Middleware\BlockWritesWhenReadOnly;
use App\Http\Middleware\EndSessionsAfterCredentialChange;
use App\Http\Middleware\EnsureClientAccess;
use App\Http\Middleware\EnsureDriverAccess;
use App\Http\Middleware\EnsureOfficeAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoStoreForAuthenticated;
use App\Http\Middleware\PreventDuplicateSubmission;
use App\Http\Middleware\ResetTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Application Bootstrap
//  Location: bootstrap/app.php
//
//  Routing, middleware and error handling for the whole app.
//
//  On EVERY web request, in this order:
//    ResetTenant             → forget the previous request's company
//    SetLocale               → ar / en for this person
//    HandleInertiaRequests   → shared props for every Vue screen
//    EndSessionsAfterCredentialChange → a password / PIN change ends the
//                              person's other sessions (audit Q23)
//    SecurityHeaders         → CSP, frame, no-sniff, HSTS … (audit Q24)
//    NoStoreForAuthenticated → signed-in pages are never cached by a
//                              browser or proxy (they hold company data)
//
//  Route aliases (used in routes/*.php):
//    super.admin   → /admin    the platform owner only
//    office        → /office   company admin + office users
//    client.portal → /client   client-portal users
//    driver.app    → /driver/api  the Driver App (JSON)
//    read-only     → blocks changes when the subscription has ended
//    no-duplicate  → ignores the same form sent twice in a few seconds
// ══════════════════════════════════════════════════════════════════

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(prepend: [
            ResetTenant::class,
        ], append: [
            EndSessionsAfterCredentialChange::class,
            SecurityHeaders::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            NoStoreForAuthenticated::class,
        ]);

        // Behind a load balancer or hosting proxy every request seems to come from the proxy's
        // address, so all users would share one IP (one login throttle, useless audit IPs).
        // Set TRUSTED_PROXIES in .env to the proxy address(es), comma-separated, or '*' when the
        // app can only be reached through the proxy. Unset = trust none (the safe default).
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        $middleware->alias([
            'super.admin'   => EnsureSuperAdmin::class,
            'office'        => EnsureOfficeAccess::class,
            'client.portal' => EnsureClientAccess::class,
            'driver.app'    => EnsureDriverAccess::class,
            'read-only'     => BlockWritesWhenReadOnly::class,
            'no-duplicate'  => PreventDuplicateSubmission::class,
        ]);

        // Signing out must always work, even when the page was open so
        // long that its security token expired ("419 Page Expired").
        // A forged sign-out can only sign someone out, so it is safe.
        $middleware->validateCsrfTokens(except: ['logout', 'driver/api/logout', 'csp-report']);

        // The portal gates must run BEFORE Laravel looks up the record in
        // an address (/office/vehicles/5). They set the company, so the
        // lookup only finds that company's records — vehicle 5 of another
        // company answers "not found". (Route-model binding otherwise runs
        // first, before any company is known.)
        foreach ([EnsureOfficeAccess::class, EnsureClientAccess::class, EnsureDriverAccess::class] as $gate) {
            $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, $gate);
        }

        // The "password changed elsewhere" check must run BEFORE any sign-in check (the 'auth' gate and the
        // portal gates above). If it ran after them, it would sign the person out when the page was already
        // let through, and the page would crash (500) instead of sending the person to the sign-in page.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            EndSessionsAfterCredentialChange::class
        );

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // A throttled sign-in must be VISIBLE. A bare 429 page is not
        // an Inertia response, so the form would silently do nothing,
        // the person would retry, and every retry extends the wait.
        // Send it back as a normal form error instead.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->header('X-Inertia')) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return back()->withErrors([
                'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        });
    })
    ->create();
