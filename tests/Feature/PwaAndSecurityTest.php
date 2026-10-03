<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: offline helper and "never store office pages"
//  Location: tests/Feature/PwaAndSecurityTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §6
// ══════════════════════════════════════════════════════════════════

class PwaAndSecurityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_the_service_worker_is_served_from_the_root_and_never_cached(): void
    {
        $path = public_path('build/sw.js');
        $created = ! is_file($path);
        if ($created) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, '// test');
        }

        try {
            $response = $this->get('/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/');
            $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        } finally {
            if ($created) {
                unlink($path);
            }
        }
    }

    public function test_signed_in_office_pages_tell_the_browser_not_to_store_them(): void
    {
        $response = $this->actingAs($this->companyAdmin(), 'web')->get('/office')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_pages_are_installable(): void
    {
        $this->get('/login')->assertSee('manifest.webmanifest', false);
        $this->get('/driver')->assertSee('manifest.webmanifest', false);
    }

    /**
     * Every file the offline helper lists must really exist: if even one
     * answers "not found", the browser rejects the whole helper and the
     * Driver App no longer opens without internet (bug found in Step 2 —
     * "manifest.webmanifest" was listed at the site root).
     */
    public function test_every_file_the_offline_helper_lists_can_be_downloaded(): void
    {
        $sw = public_path('build/sw.js');
        if (! is_file($sw)) {
            $this->markTestSkipped('Run "npm run build" first.');
        }

        preg_match_all('/"url":"([^"]+)"/', file_get_contents($sw), $m);
        $this->assertNotEmpty($m[1]);

        foreach (array_unique($m[1]) as $url) {
            $path = '/'.ltrim($url, '/');
            $file = public_path(ltrim($path, '/'));
            if (is_file($file)) {
                continue; // served straight from public/ by the web server
            }
            $this->get($path)->assertOk();
        }
    }
}
