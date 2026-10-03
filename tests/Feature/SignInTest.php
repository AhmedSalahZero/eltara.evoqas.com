<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: sign-in for office, Super Admin and client portal
//  Location: tests/Feature/SignInTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §2
// ══════════════════════════════════════════════════════════════════

class SignInTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_the_sign_in_page_opens_in_arabic_by_default(): void
    {
        $this->get('/login')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
    }

    public function test_the_front_door_sends_guests_to_sign_in(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_an_office_user_signs_in_with_email_and_lands_in_the_office(): void
    {
        $user = $this->officeUser();

        $this->post('/login', ['login' => strtoupper($user->email), 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/')->assertRedirect('/office');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_an_office_user_can_sign_in_with_the_mobile_number_typed_any_way(): void
    {
        $user = $this->officeUser(attributes: ['phone' => '01001234567']);

        $this->post('/login', ['login' => '+20 100 123 4567', 'password' => 'password']);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_the_super_admin_lands_in_the_platform_area(): void
    {
        $admin = $this->superAdmin();

        $this->post('/login', ['login' => $admin->email, 'password' => 'password']);
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_a_client_user_signs_in_on_the_same_page_and_lands_in_the_client_portal(): void
    {
        $client = $this->clientUser();

        $this->post('/login', ['login' => $client->email, 'password' => 'password'])->assertRedirect('/client');
        $this->assertAuthenticatedAs($client, 'client');
        $this->assertGuest('web');
    }

    public function test_a_wrong_password_is_refused_with_a_message(): void
    {
        $user = $this->officeUser();

        $this->post('/login', ['login' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
    }

    public function test_after_five_wrong_passwords_the_account_waits(): void
    {
        $user = $this->officeUser();

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['login' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
    }

    public function test_an_account_that_was_never_activated_cannot_sign_in(): void
    {
        $user = $this->officeUser(attributes: ['email_verified_at' => null]);

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
    }

    public function test_a_suspended_user_cannot_sign_in(): void
    {
        $user = $this->officeUser(attributes: ['is_active' => false]);

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
    }

    public function test_nobody_in_a_suspended_company_can_sign_in(): void
    {
        $company = $this->company(['status' => CompanyStatus::Suspended]);
        $user = $this->officeUser($company);
        $client = $this->clientUser($company);

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => $client->email, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
        $this->assertGuest('client');
    }

    public function test_suspending_a_company_signs_out_someone_already_working(): void
    {
        $company = $this->company();
        $user = $this->officeUser($company);

        $this->actingAs($user, 'web')->get('/office')->assertOk();

        $company->update(['status' => CompanyStatus::Suspended]);
        $user->unsetRelation('company'); // a new request reads the company afresh

        $this->get('/office')->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_signing_out(): void
    {
        $this->actingAs($this->officeUser(), 'web')->post('/logout')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_a_client_user_cannot_open_the_office(): void
    {
        $this->actingAs($this->clientUser(), 'client')->get('/office')->assertRedirect('/login');
    }

    public function test_an_office_user_cannot_open_the_client_portal(): void
    {
        $this->actingAs($this->officeUser(), 'web')->get('/client')->assertRedirect('/login');
    }
}
