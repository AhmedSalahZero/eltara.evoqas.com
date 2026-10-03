<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: changing your own mobile number needs your password
//  Location: tests/Feature/ProfilePhoneChangeTest.php
//  The mobile is a sign-in name, so a screen left open must not be
//  enough to give the account a new one.
// ══════════════════════════════════════════════════════════════════

class ProfilePhoneChangeTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_changing_the_mobile_needs_the_current_password(): void
    {
        $user = $this->officeUser(null, [], ['phone' => '01012345678']);

        $this->actingAs($user, 'web')->patch('/profile', ['name' => $user->name, 'phone' => '01112345678'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('01012345678', $user->fresh()->phone);

        $this->patch('/profile', ['name' => $user->name, 'phone' => '01112345678', 'current_password' => 'wrong-one'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('01012345678', $user->fresh()->phone);
    }

    public function test_with_the_right_password_the_mobile_changes_and_is_written_to_the_audit_log(): void
    {
        $user = $this->officeUser(null, [], ['phone' => '01012345678']);

        $this->actingAs($user, 'web')->patch('/profile', ['name' => $user->name, 'phone' => '01112345678', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame('01112345678', $user->fresh()->phone);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'profile.phone_changed')->exists());
    }

    public function test_changing_only_the_name_needs_no_password(): void
    {
        $user = $this->officeUser(null, [], ['phone' => '01012345678']);

        $this->actingAs($user, 'web')->patch('/profile', ['name' => 'New Name', 'phone' => '01012345678'])->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertFalse(AuditLog::query()->withoutGlobalScopes()->where('action', 'profile.phone_changed')->exists());
    }

    public function test_the_same_rule_holds_for_client_users(): void
    {
        $client = $this->clientUser(null, ['phone' => '01012345678']);

        $this->actingAs($client, 'client')->patch('/client/profile', ['name' => $client->name, 'phone' => '01112345678'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('01012345678', $client->fresh()->phone);

        $this->patch('/client/profile', ['name' => $client->name, 'phone' => '01112345678', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame('01112345678', $client->fresh()->phone);
    }
}
