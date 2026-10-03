<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

// ══════════════════════════════════════════════════════════════════
//  El Tara — eltara:check ("is everything set up safely?")
//  Location: app/Console/Commands/CheckSystem.php
//
//  Run:  php artisan eltara:check
//  Prints OK / PROBLEM for each safety-relevant setting. Exit code is
//  non-zero when something is wrong, so it can be put in a deploy script.
//  Nothing is changed by this command.
// ══════════════════════════════════════════════════════════════════

class CheckSystem extends Command
{
    protected $signature = 'eltara:check';

    protected $description = 'Check that the safety settings (cache, sessions, queue) are set up properly';

    public function handle(): int
    {
        $problems = 0;

        $row = function (bool $ok, string $label, string $hint = '') use (&$problems): void {
            if (! $ok) {
                $problems++;
            }
            $this->line(($ok ? '  OK       ' : '  PROBLEM  ').$label.(! $ok && $hint !== '' ? "\n             → ".$hint : ''));
        };

        $this->info('El Tara — system check');

        // 1. The database answers.
        try {
            DB::select('select 1');
            $row(true, 'Database answers');
        } catch (Throwable $e) {
            $row(false, 'Database answers', $e->getMessage());
        }

        // 2. The safety store really remembers (double-click guard, login lockouts).
        $store = (string) config('cache.durable');
        try {
            $key = 'check:'.Str::random(12);
            $cache = Cache::store($store);
            $first = $cache->add($key, 1, 30);
            $second = $cache->add($key, 1, 30);
            $cache->forget($key);
            $row($first && ! $second, "Double-click guard and lockouts remember (store: {$store})", 'Use CACHE_STORE=database (or redis) in the .env file.');
        } catch (Throwable $e) {
            $row(false, "Double-click guard and lockouts remember (store: {$store})", $e->getMessage());
        }

        $row(
            ! in_array(config('cache.default'), ['array', 'null'], true),
            'Normal cache is not "array" or "null" (store: '.config('cache.default').')',
            'Set CACHE_STORE=database in the .env file.',
        );

        // 3. Sessions are kept somewhere that survives a restart.
        $row(
            ! in_array(config('session.driver'), ['array', 'cookie'], true),
            'Sign-in sessions are stored safely (driver: '.config('session.driver').')',
            'Set SESSION_DRIVER=database in the .env file.',
        );

        // 4. Queue: nothing is queued today; warn only about "sync"-less surprises.
        $this->line('  INFO     Queue: '.config('queue.default').' (the app queues no jobs today, so no worker is needed)');

        // 5. Live-server settings.
        if (app()->environment('production')) {
            $row(! config('app.debug'), 'Debug mode is off', 'Set APP_DEBUG=false in the .env file.');
        }

        $this->newLine();
        $problems === 0 ? $this->info('All good.') : $this->error("{$problems} problem(s) found. Show this screen to your developer.");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
