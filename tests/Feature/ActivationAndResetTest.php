<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\AccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: activation links and "forgot password"
//  Location: tests/Feature/ActivationAndResetTest.php
//  Feature doc: docs/STEP_01_FOUNDATION.md §2
// ══════════════════════════════════════════════════════════════════

class ActivationAndResetTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private const NEW_PASSWORD = 'Tara@2026x';

    public function test_the_activation_link_lets_the_person_choose_a_password_and_activates_the_account(): void
    {
        Notification::fake();
        $user = $this->officeUser(attributes: ['email_verified_at' => null]);

        app(AccountInvitation::class)->send($user);

        $token = null;
        Notification::assertSentTo($user, ActivateAccountNotification::class, function ($n) use (&$token) {
            $token = (fn () => $this->token)->call($n);

            return true;
        });

        $this->get("/activate/{$token}?email={$user->email}")->assertOk();

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email, 'mode' => 'activate',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotNull($user->email_verified_at);

        $this->post('/login', ['login' => $user->email, 'password' => self::NEW_PASSWORD]);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_weak_password_is_refused(): void
    {
        $user = $this->officeUser();
        $token = Password::broker('invites')->createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email, 'mode' => 'activate',
            'password' => 'simple', 'password_confirmation' => 'simple',
        ])->assertSessionHasErrors('password');
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $user = $this->officeUser();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token', 'email' => $user->email, 'mode' => 'reset',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');
    }

    public function test_forgot_password_sends_a_link_to_office_and_client_users_and_says_the_same_for_unknown_emails(): void
    {
        Notification::fake();
        $user = $this->officeUser();
        $client = $this->clientUser();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => $client->email])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertSentTo($client, ResetPasswordNotification::class);
    }

    public function test_a_client_user_can_reset_their_password(): void
    {
        $client = $this->clientUser();
        $token = Password::broker('clients')->createToken($client);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $client->email, 'mode' => 'reset', 'for' => 'client',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $client->fresh()->password));
    }

    public function test_a_client_activation_link_still_works_when_the_for_client_part_was_lost(): void
    {
        // e.g. the link was copied from the e-mail log file and lost "&for=client"
        $client = $this->clientUser(attributes: ['email_verified_at' => null]);
        $token = Password::broker('client_invites')->createToken($client);

        $this->get('/activate/'.$token.'?email='.urlencode($client->email))
            ->assertInertia(fn ($p) => $p->where('for', 'client'));

        $this->post('/reset-password', [
            'token' => $token, 'email' => $client->email, 'mode' => 'activate',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors()->assertRedirect('/login');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $client->fresh()->password));
        $this->assertNotNull($client->fresh()->email_verified_at);
    }

    public function test_the_activation_email_renders_in_arabic_and_english(): void
    {
        $user = User::factory()->for($this->company())->create(['language' => 'ar']);
        $html = (new ActivateAccountNotification('tok'))->toMail($user)->render();
        $this->assertStringContainsString('التارة', (string) $html);
        $this->assertStringNotContainsString('emails.', (string) $html);

        $user->language = 'en';
        $html = (new ActivateAccountNotification('tok'))->toMail($user)->render();
        $this->assertStringContainsString('Activate your El Tara account', (string) $html);
    }
}
