<?php

namespace Tests\Feature;

use App\Support\DocumentExpiry;
use App\Support\Permissions;
use ReflectionProperty;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: follow-up fixes from the code review
//  Location: tests/Feature/HardeningFollowUpTest.php
//    · the "document ends soon" window comes from the settings
//    · remembered permission answers never survive into the next request
// ══════════════════════════════════════════════════════════════════

class HardeningFollowUpTest extends TestCase
{
    public function test_the_document_alert_window_is_30_days_unless_the_settings_say_otherwise(): void
    {
        $date = today()->addDays(20);

        $this->assertSame('soon', DocumentExpiry::describe($date)['state']);

        config(['eltara.documents.alert_days' => 10]);

        $this->assertSame('ok', DocumentExpiry::describe($date)['state']);
        $this->assertSame('soon', DocumentExpiry::describe(today()->addDays(10))['state']);
        $this->assertSame('expired', DocumentExpiry::describe(today()->subDay())['state']);
    }

    public function test_remembered_permission_answers_are_forgotten_when_a_new_request_starts(): void
    {
        $memo = new ReflectionProperty(Permissions::class, 'memo');
        $memo->setValue(null, [999 => ['trips.view']]);

        $this->get('/login')->assertOk();

        $this->assertSame([], $memo->getValue());
    }

    public function test_the_route_list_sent_to_every_browser_leaves_out_routes_no_screen_uses(): void
    {
        $page = $this->get('/login')->assertOk();

        // What the sign-in screen and the portals really use is still there …
        $page->assertSee('"login"', false)->assertSee('"office.home"', false)->assertSee('"client.home"', false);

        // … and the Driver App's, the PWA's and the email-link routes are not.
        $page->assertDontSee('"driver.app"', false)->assertDontSee('"csp.report"', false)->assertDontSee('"pwa.manifest"', false)
            ->assertDontSee('"activation.show"', false);
    }

    public function test_print_pages_wait_for_the_person_to_press_print(): void
    {
        foreach (['trips.print', 'office.report-print', 'office.dashboard-print', 'client.statement'] as $view) {
            $source = file_get_contents(resource_path('views/'.str_replace('.', '/', $view).'.blade.php'));

            // No dialog opens by itself, and the button does not rely on an inline handler a strict policy would block.
            $this->assertStringNotContainsString('setTimeout', $source, $view);
            $this->assertStringNotContainsString('onclick=', $source, $view);
            $this->assertStringContainsString('data-print', $source, $view);
        }
    }

    public function test_the_driver_app_page_is_only_kept_when_it_fits_the_files_of_the_same_build(): void
    {
        $source = file_get_contents(resource_path('js/pwa/sw.js'));

        // Saved per build version, and only if every built file it names is in this worker's own list
        // (a new page next to old files is what leaves the screen blank offline).
        $this->assertStringContainsString('SHELL_PREFIX}${version}', $source);
        $this->assertStringContainsString('fitsTheFiles(', $source);
        // A failed page download must never stop the files from being installed.
        $this->assertStringContainsString('keepShell().catch(', $source);
    }
}
