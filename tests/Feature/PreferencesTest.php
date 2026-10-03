<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: language and theme per person
//  Location: tests/Feature/PreferencesTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §2
// ══════════════════════════════════════════════════════════════════

class PreferencesTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_an_office_user_switches_to_english_and_it_is_remembered(): void
    {
        $user = $this->officeUser();

        $this->actingAs($user, 'web')->post('/preferences', ['language' => 'en', 'theme' => 'light'])->assertRedirect();

        $this->assertSame('en', $user->fresh()->language);
        $this->get('/office')->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)->assertSee('class="light"', false);
    }

    public function test_a_guest_can_switch_the_sign_in_page_language(): void
    {
        $this->post('/preferences', ['language' => 'en']);
        $this->get('/login')->assertSee('lang="en"', false);
    }

    public function test_a_client_user_saves_their_own_preference(): void
    {
        $client = $this->clientUser();

        $this->actingAs($client, 'client')->post('/preferences', ['language' => 'en', 'portal' => 'client']);
        $this->assertSame('en', $client->fresh()->language);
    }

    public function test_only_known_values_are_accepted(): void
    {
        $this->actingAs($this->officeUser(), 'web')->post('/preferences', ['language' => 'fr'])->assertSessionHasErrors('language');
    }
}
