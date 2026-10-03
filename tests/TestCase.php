<?php

namespace Tests;

use App\Support\Permissions;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Base Test Case
//  Location: tests/TestCase.php
//
//  Every automated test extends this. Run them all with:
//      php artisan test
//  They use a temporary in-memory database (phpunit.xml), so your
//  real data is never touched and no extra database is needed.
//
//  withoutVite()       → tests check the server, not the built screens
//  Permissions::flush  → forget remembered permission answers
//  Tenant::clear       → forget the company of the previous test
// ══════════════════════════════════════════════════════════════════

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::flush();
        Tenant::clear();
    }
}
