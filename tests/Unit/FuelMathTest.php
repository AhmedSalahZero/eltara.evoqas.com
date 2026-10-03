<?php

namespace Tests\Unit;

use App\Services\Fuel\FuelMath;
use PHPUnit\Framework\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: km per litre, tank to tank (Step 6, Scope §6.10)
//  Location: tests/Unit/FuelMathTest.php
//  Pure arithmetic: km between two fill-ups ÷ litres of the later fill;
//  the first fill (or one without an odometer) shows no economy; an
//  odometer that goes down is "bad"; a truck is flagged when it is
//  more than the set % below its standard.
// ══════════════════════════════════════════════════════════════════

class FuelMathTest extends TestCase
{
    private function rows(): array
    {
        return FuelMath::perFill([
            ['id' => 1, 'odometer' => 10000, 'litres' => 100.0],
            ['id' => 2, 'odometer' => 10300, 'litres' => 100.0],
            ['id' => 3, 'odometer' => null, 'litres' => 50.0],
            ['id' => 4, 'odometer' => 10500, 'litres' => 80.0],
            ['id' => 5, 'odometer' => 10400, 'litres' => 10.0],
        ], 9800, 3.0, 7);
    }

    public function test_km_per_litre_is_measured_from_one_fill_to_the_next(): void
    {
        $rows = $this->rows();

        $this->assertSame(200, $rows[0]['km']);
        $this->assertEqualsWithDelta(2.0, $rows[0]['kmpl'], 0.001);
        $this->assertTrue($rows[0]['flagged']);            // 2.0 is more than 7% under 3.0
        $this->assertSame(300, $rows[1]['km']);
        $this->assertEqualsWithDelta(3.0, $rows[1]['kmpl'], 0.001);
        $this->assertFalse($rows[1]['flagged']);
    }

    public function test_a_fill_without_an_odometer_shows_no_economy(): void
    {
        $rows = $this->rows();

        $this->assertNull($rows[2]['km']);
        $this->assertSame(200, $rows[3]['km']);             // next fill measures from the last good reading
        $this->assertEqualsWithDelta(2.5, $rows[3]['kmpl'], 0.001);
    }

    public function test_an_odometer_that_goes_down_is_marked_bad(): void
    {
        $this->assertTrue($this->rows()[4]['bad']);
    }

    public function test_the_flag_boundary(): void
    {
        $this->assertFalse(FuelMath::isFlagged(2.79, 3.0, 7));
        $this->assertTrue(FuelMath::isFlagged(2.78, 3.0, 7));
        $this->assertFalse(FuelMath::isFlagged(null, 3.0, 7));
        $this->assertFalse(FuelMath::isFlagged(1.0, null, 7));
    }

    public function test_a_trucks_summary(): void
    {
        $s = FuelMath::summary($this->rows(), [1 => 100.0, 2 => 100.0, 3 => 50.0, 4 => 80.0, 5 => 10.0], 3.0, 7);

        $this->assertSame(700, $s['km']);
        $this->assertEqualsWithDelta(280.0, $s['litres'], 0.001);
        $this->assertEqualsWithDelta(2.5, $s['kmpl'], 0.001);
        $this->assertEqualsWithDelta(-16.7, $s['variance'], 0.001);
    }
}
