<?php

namespace Tests\Feature;

use App\Models\GaEntry;
use App\Services\Closing\MonthCloseService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: session ending, browser security headers, spreadsheet safety
//  Location: tests/Feature/SecurityHardeningTest.php
//  Audit report items Q23, Q24, Q25.
// ══════════════════════════════════════════════════════════════════

class SecurityHardeningTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    private const NEW_PASSWORD = 'Tara@2026new';

    // ── Q23: a password / PIN change ends the other sessions ──────

    public function test_an_office_session_ends_when_the_password_is_changed_elsewhere(): void
    {
        $user = $this->officeUser();

        $this->post('/login', ['login' => $user->email, 'password' => 'password']);
        $this->get('/office')->assertOk();

        // Changed from another place (another device, or an admin) — straight in the database, so this session
        // cannot be the one that made the change.
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(self::NEW_PASSWORD)]);

        $this->app['auth']->forgetGuards(); // a real request starts with no remembered user
        $this->get('/office')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_the_person_who_changes_his_own_password_stays_signed_in(): void
    {
        $user = $this->officeUser();

        $this->post('/login', ['login' => $user->email, 'password' => 'password']);
        $this->get('/office')->assertOk();

        $this->put('/office/profile/password', [
            'current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->get('/office')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_client_session_ends_when_the_password_is_changed_elsewhere(): void
    {
        $client = $this->clientUser();

        $this->post('/login', ['login' => $client->email, 'password' => 'password']);
        $this->get('/client')->assertOk();

        DB::table('client_users')->where('id', $client->id)->update(['password' => Hash::make(self::NEW_PASSWORD)]);

        $this->app['auth']->forgetGuards();
        $this->get('/client')->assertRedirect('/login');
    }

    public function test_a_drivers_session_ends_when_his_pin_is_reset(): void
    {
        $driver = $this->driver();

        $this->postJson('/driver/api/login', ['mobile' => $driver->mobile, 'pin' => '1234'])->assertOk();
        $this->getJson('/driver/api/me')->assertOk();

        DB::table('drivers')->where('id', $driver->id)->update(['pin' => Hash::make('4321')]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/driver/api/me')->assertStatus(401);
    }

    public function test_saving_a_new_password_replaces_the_remember_me_token(): void
    {
        $user = $this->officeUser();
        $driver = $this->driver();
        $userToken = $user->remember_token;
        $driverToken = $driver->remember_token;

        $user->forceFill(['password' => self::NEW_PASSWORD])->save();
        $driver->forceFill(['pin' => '9876'])->save();

        $this->assertNotSame($userToken, $user->fresh()->remember_token);
        $this->assertNotSame($driverToken, $driver->fresh()->remember_token);

        // Changing something else does not touch the token.
        $token = $user->fresh()->remember_token;
        $user->fresh()->forceFill(['name' => 'Someone Else'])->save();
        $this->assertSame($token, $user->fresh()->remember_token);
    }

    // ── Q24: security headers ─────────────────────────────────────

    public function test_every_page_carries_the_security_headers(): void
    {
        config(['security.csp' => 'enforce']);
        $response = $this->get('/login')->assertOk();

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotNull($response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('camera=(self)', $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('geolocation=(self)', $response->headers->get('Permissions-Policy'));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-(inline|eval)'/", $csp);
    }

    public function test_the_secret_nonce_in_the_policy_is_the_one_on_the_inline_script(): void
    {
        config(['security.csp' => 'enforce']);
        $response = $this->get('/login')->assertOk();

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m);
        $this->assertNotEmpty($m[1] ?? null, 'The policy has no nonce.');
        $this->assertStringContainsString('nonce="'.$m[1].'"', $response->getContent());

        // A fresh nonce for every request.
        preg_match("/'nonce-([^']+)'/", $this->get('/login')->headers->get('Content-Security-Policy'), $again);
        $this->assertNotSame($m[1], $again[1]);
    }

    public function test_the_print_pages_script_carries_the_nonce_too(): void
    {
        config(['security.csp' => 'enforce']);
        $trip = $this->runningTrip();

        $response = $this->actingAs($this->admin, 'web')->get("/office/trips/{$trip->id}/print")->assertOk();

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m);
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', $response->getContent());
    }

    public function test_hsts_is_sent_only_on_https(): void
    {
        config(['security.csp' => 'enforce']);
        $this->assertNull($this->get('http://localhost/login')->headers->get('Strict-Transport-Security'));

        $secure = $this->get('https://localhost/login');
        $this->assertStringContainsString('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('upgrade-insecure-requests', $secure->headers->get('Content-Security-Policy'));
    }

    public function test_the_policy_starts_in_report_only_mode_and_can_be_enforced_or_switched_off(): void
    {
        // Default: nothing is blocked, the browser only reports what WOULD be blocked.
        $default = $this->get('/login');
        $this->assertNull($default->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('report-uri /csp-report', $default->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertSame('DENY', $default->headers->get('X-Frame-Options'));

        config(['security.csp' => 'enforce']);
        $enforced = $this->get('/login');
        $this->assertNotNull($enforced->headers->get('Content-Security-Policy'));
        $this->assertNull($enforced->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('report-uri /csp-report', $enforced->headers->get('Content-Security-Policy'));

        config(['security.csp' => 'off']);
        $off = $this->get('/login');
        $this->assertNull($off->headers->get('Content-Security-Policy'));
        $this->assertNull($off->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertSame('DENY', $off->headers->get('X-Frame-Options'));
    }

    public function test_a_report_from_the_browser_is_written_to_the_csp_log(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('csp')->once()->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'script-src') && $context['blocked'] === 'https://evil.example/x.js');

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'document-uri' => 'https://tara.example/office', 'effective-directive' => 'script-src', 'blocked-uri' => 'https://evil.example/x.js', 'disposition' => 'report',
        ]]))->assertNoContent();
    }

    public function test_reports_caused_by_browser_extensions_and_junk_are_ignored(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->never();

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'effective-directive' => 'script-src', 'blocked-uri' => 'chrome-extension://abcdef/inject.js',
        ]]))->assertNoContent();

        $this->call('POST', '/csp-report', [], [], [], [], 'not json at all')->assertNoContent();
    }

    // ── Q25: spreadsheet formulas ─────────────────────────────────

    public function test_text_that_looks_like_a_formula_is_exported_as_plain_text(): void
    {
        $evil = '=HYPERLINK("http://evil.example/steal","click")';

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', $evil);
        $sheet->setCellValue('A2', '@SUM(1+1)');
        $sheet->fromArray([[$evil, 'ok']], null, 'A3');
        $sheet->setCellValue('A4', 42);
        $sheet->setCellValue('A5', 'Normal text');

        foreach (['A1', 'A2', 'A3'] as $cell) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), "{$cell} must be text");
        }
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A4')->getDataType());

        // After saving and reopening the file, it still holds the words and no formula.
        $path = tempnam(sys_get_temp_dir(), 'tara').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $back = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertFalse($back->getCell('A1')->isFormula());
        $this->assertSame($evil, $back->getCell('A1')->getValue());
        $this->assertSame(42, (int) $back->getCell('A4')->getValue());
    }

    public function test_the_month_close_import_never_calculates_formulas_and_cleans_formula_like_names(): void
    {
        $this->setUpFleet();

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Rent');
        $sheet->setCellValueExplicit('B1', '=100+50', DataType::TYPE_FORMULA);          // Excel saved its result (150) with it
        $sheet->setCellValue('A2', '=cmd|x');
        $sheet->setCellValue('B2', 20);
        $sheet->setCellValue('A3', 'Web');
        $sheet->setCellValueExplicit('B3', '=WEBSERVICE("http://127.0.0.1:9/x")', DataType::TYPE_FORMULA);

        $path = tempnam(sys_get_temp_dir(), 'tara').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->setPreCalculateFormulas(false)->save($path);

        // No cached results were written this time, so nothing may be invented from the formulas.
        $count = Tenant::forCompany($this->co->id, fn () => app(MonthCloseService::class)->importLines(Carbon::parse('2026-08-01'), $path, false, $this->admin));
        @unlink($path);

        $lines = GaEntry::query()->withoutGlobalScopes()->orderBy('id')->get(['label', 'amount']);

        $this->assertSame(1, $count);
        $this->assertSame('cmd|x', $lines[0]->label);
        $this->assertEquals(20, $lines[0]->amount);
    }

    public function test_the_month_close_import_uses_the_result_excel_saved_with_a_formula(): void
    {
        $this->setUpFleet();

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Rent');
        $sheet->setCellValueExplicit('B1', '=100+50', DataType::TYPE_FORMULA);

        $path = tempnam(sys_get_temp_dir(), 'tara').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);                              // pre-calculation on: 150 is saved with the formula

        Tenant::forCompany($this->co->id, fn () => app(MonthCloseService::class)->importLines(Carbon::parse('2026-08-01'), $path, false, $this->admin));
        @unlink($path);

        $this->assertEquals(150, GaEntry::query()->withoutGlobalScopes()->value('amount'));
    }
}
