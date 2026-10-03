<?php

namespace Tests\Unit;

use App\Services\Closing\MonthSplit;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: how a trip's km are split between months (Step 6)
//  Location: tests/Unit/MonthSplitTest.php
//  Pure date arithmetic (Scope §6.13, §10): the three rules — split
//  by hours, whole trip to the start month, whole trip to the
//  delivery month — and that the parts always add up to the trip's km.
// ══════════════════════════════════════════════════════════════════

class MonthSplitTest extends TestCase
{
    private function at(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('Africa/Cairo'));
    }

    public function test_a_trip_inside_one_month_keeps_all_its_km(): void
    {
        $p = MonthSplit::parts($this->at('2026-09-10 06:00'), $this->at('2026-09-11 06:00'), 450, 'hours');

        $this->assertSame(['2026-09'], array_keys($p));
        $this->assertEqualsWithDelta(450.0, $p['2026-09']['km'], 0.001);
        $this->assertEqualsWithDelta(24.0, $p['2026-09']['hours'], 0.001);
    }

    public function test_by_hours_each_month_takes_its_share(): void
    {
        // 31 Aug 20:00 → 1 Sep 08:00 = 4 hours in August, 8 in September.
        $p = MonthSplit::parts($this->at('2026-08-31 20:00'), $this->at('2026-09-01 08:00'), 450, 'hours');

        $this->assertEqualsWithDelta(150.0, $p['2026-08']['km'], 0.001);
        $this->assertEqualsWithDelta(300.0, $p['2026-09']['km'], 0.001);
        $this->assertEqualsWithDelta(4.0, $p['2026-08']['hours'], 0.001);
    }

    public function test_start_and_delivery_rules_put_the_whole_trip_in_one_month(): void
    {
        $start = MonthSplit::parts($this->at('2026-08-31 20:00'), $this->at('2026-09-01 08:00'), 450, 'start');
        $end = MonthSplit::parts($this->at('2026-08-31 20:00'), $this->at('2026-09-01 08:00'), 450, 'delivery');

        $this->assertSame(['2026-08'], array_keys($start));
        $this->assertEqualsWithDelta(450.0, $start['2026-08']['km'], 0.001);
        $this->assertSame(['2026-09'], array_keys($end));
    }

    public function test_parts_always_add_up_to_the_trips_km(): void
    {
        $p = MonthSplit::parts($this->at('2026-08-31 23:00'), $this->at('2026-10-01 01:00'), 1000, 'hours');

        $this->assertCount(3, $p);
        $this->assertEqualsWithDelta(1000.0, round(array_sum(array_column($p, 'km')), 2), 0.001);
    }

    public function test_an_end_before_the_start_does_not_break_it(): void
    {
        $p = MonthSplit::parts($this->at('2026-09-10 06:00'), $this->at('2026-09-09 06:00'), 100, 'hours');

        $this->assertEqualsWithDelta(100.0, $p['2026-09']['km'], 0.001);
    }

    public function test_the_clock_change_in_cairo_does_not_lose_km(): void
    {
        $p = MonthSplit::parts($this->at('2026-10-31 22:00'), $this->at('2026-11-01 02:00'), 400, 'hours');

        $this->assertEqualsWithDelta(400.0, round(array_sum(array_column($p, 'km')), 2), 0.001);
    }
}
