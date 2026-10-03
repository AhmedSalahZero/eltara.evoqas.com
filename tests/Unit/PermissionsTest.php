<?php

namespace Tests\Unit;

use App\Support\Permissions;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: the permission list (config/permissions.php)
//  Location: tests/Unit/PermissionsTest.php
// ══════════════════════════════════════════════════════════════════

class PermissionsTest extends TestCase
{
    public function test_there_are_the_16_features_of_the_scope(): void
    {
        $this->assertCount(16, config('permissions.features'));
    }

    public function test_special_actions_exist_only_where_they_apply(): void
    {
        $this->assertTrue(Permissions::exists('trips.edit_price'));
        $this->assertTrue(Permissions::exists('month_close.reopen'));
        $this->assertFalse(Permissions::exists('fuel.edit_price'));
        $this->assertFalse(Permissions::exists('made.up'));
    }

    public function test_clean_drops_unknown_keys_and_duplicates(): void
    {
        $this->assertEqualsCanonicalizing(['trips.view'], Permissions::clean(['trips.view', 'trips.view', 'nope']));
    }

    public function test_every_feature_has_a_label_in_both_languages(): void
    {
        foreach (Permissions::matrix() as $row) {
            $this->assertNotSame('', $row['label']);
        }
        app()->setLocale('en');
        foreach (Permissions::matrix() as $row) {
            $this->assertStringNotContainsString('permissions.', $row['label']);
        }
    }
}
