<?php

namespace Tests\Unit;

use App\Support\EgyptPhone;
use PHPUnit\Framework\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: Egyptian mobile numbers typed any way
//  Location: tests/Unit/EgyptPhoneTest.php
// ══════════════════════════════════════════════════════════════════

class EgyptPhoneTest extends TestCase
{
    public function test_numbers_are_stored_in_one_form(): void
    {
        foreach (['01001234567', '+201001234567', '00201001234567', '0100 123 4567', '٠١٠٠١٢٣٤٥٦٧'] as $typed) {
            $this->assertSame('01001234567', EgyptPhone::normalize($typed), $typed);
        }
    }

    public function test_the_pattern_accepts_egyptian_mobiles_only(): void
    {
        $this->assertMatchesRegularExpression(EgyptPhone::PATTERN, '01112223334');
        $this->assertDoesNotMatchRegularExpression(EgyptPhone::PATTERN, '0223456789');
    }
}
