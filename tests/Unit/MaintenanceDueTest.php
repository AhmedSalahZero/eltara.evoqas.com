<?php

namespace Tests\Unit;

use App\Services\Maintenance\MaintenanceDue;
use PHPUnit\Framework\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: when the next periodic service is due
//  Location: tests/Unit/MaintenanceDueTest.php
//  Pure: the latest finished job for a truck and item, compared with
//  the odometer and the date. Overdue wins over "soon"; an older job
//  never replaces a newer one.
// ══════════════════════════════════════════════════════════════════

class MaintenanceDueTest extends TestCase
{
    public function test_other_jobs_with_different_notes_are_different_items_but_oil_is_one(): void
    {
        $jobs = [
            ['id' => 1, 'vehicle_id' => 5, 'category' => 'other', 'note' => 'Brakes', 'completed_at' => '2026-01-01 10:00:00'],
            ['id' => 2, 'vehicle_id' => 5, 'category' => 'other', 'note' => 'Gearbox oil', 'completed_at' => '2026-02-01 10:00:00'],
            ['id' => 3, 'vehicle_id' => 5, 'category' => 'other', 'note' => '  brakes ', 'completed_at' => '2026-03-01 10:00:00'],
            ['id' => 4, 'vehicle_id' => 5, 'category' => 'oil', 'note' => 'a', 'completed_at' => '2026-01-01 10:00:00'],
            ['id' => 5, 'vehicle_id' => 5, 'category' => 'oil', 'note' => 'b', 'completed_at' => '2026-02-01 10:00:00'],
        ];

        $ids = array_map(fn ($j) => $j['id'], MaintenanceDue::latest($jobs));
        sort($ids);

        // Brakes (newest = 3) and Gearbox (2) both stay; oil is one item (newest = 5).
        $this->assertSame([2, 3, 5], $ids);
    }

    public function test_kilometres_are_overdue_at_the_next_reading_soon_within_500_and_otherwise_ok(): void
    {
        $job = $this->job(['odometer_km' => 10000, 'interval_km' => 1000, 'interval_days' => null]);

        $this->assertSame(11000, MaintenanceDue::next($job)['km']);
        $this->assertSame('overdue', MaintenanceDue::state($job, 11000, '2026-01-01'));
        $this->assertSame('soon', MaintenanceDue::state($job, 10500, '2026-01-01'));   // 500 km left
        $this->assertSame('ok', MaintenanceDue::state($job, 10499, '2026-01-01'));    // 501 km left
    }

    public function test_a_date_that_has_passed_is_overdue_and_today_is_only_soon(): void
    {
        $job = $this->job(['odometer_km' => null, 'interval_km' => null, 'completed_on' => '2026-01-01', 'interval_days' => 10]);

        $this->assertSame('2026-01-11', MaintenanceDue::next($job)['on']);
        $this->assertSame('overdue', MaintenanceDue::state($job, 0, '2026-01-12'));
        $this->assertSame('soon', MaintenanceDue::state($job, 0, '2026-01-11'));
        $this->assertSame('soon', MaintenanceDue::state($job, 0, '2026-01-04'));      // 7 days before
        $this->assertSame('ok', MaintenanceDue::state($job, 0, '2026-01-03'));        // 8 days before
    }

    public function test_either_axis_being_overdue_makes_the_job_overdue(): void
    {
        $job = $this->job([
            'odometer_km' => 10000, 'interval_km' => 1000, 'completed_on' => '2026-06-01', 'interval_days' => 90,
        ]);

        $this->assertSame('overdue', MaintenanceDue::state($job, 11000, '2026-06-02'));
        $this->assertSame('soon', MaintenanceDue::state($job, 10600, '2026-08-20'));
    }

    public function test_an_open_job_or_one_without_an_interval_has_no_due_state(): void
    {
        $this->assertNull(MaintenanceDue::state($this->job(['status' => 'open']), 99999, '2026-12-01'));
        $this->assertNull(MaintenanceDue::state($this->job(['kind' => 'emergency', 'interval_km' => null, 'interval_days' => null]), 99999, '2026-12-01'));
        $this->assertNull(MaintenanceDue::state($this->job(['interval_km' => null, 'interval_days' => null, 'odometer_km' => null]), 99999, '2026-12-01'));
    }

    public function test_an_older_job_does_not_replace_a_newer_one(): void
    {
        $older = ['id' => 1, 'vehicle_id' => 7, 'category' => 'oil', 'kind' => 'periodic', 'status' => 'done', 'completed_at' => '2026-01-01 08:00:00'];
        $newer = ['id' => 2, 'vehicle_id' => 7, 'category' => 'oil', 'kind' => 'periodic', 'status' => 'done', 'completed_at' => '2026-06-01 08:00:00'];
        $sameTime = ['id' => 3, 'vehicle_id' => 7, 'category' => 'oil', 'kind' => 'periodic', 'status' => 'done', 'completed_at' => '2026-06-01 08:00:00'];
        $tyres = ['id' => 4, 'vehicle_id' => 7, 'category' => 'tyres', 'kind' => 'periodic', 'status' => 'done', 'completed_at' => '2026-02-01 08:00:00'];
        $open = ['id' => 9, 'vehicle_id' => 7, 'category' => 'oil', 'kind' => 'periodic', 'status' => 'open', 'completed_at' => '2026-12-01 08:00:00'];

        $latest = MaintenanceDue::latest([$older, $open, $tyres, $newer, $sameTime]);
        $byItem = [];
        foreach ($latest as $job) {
            $byItem[$job['category']] = $job['id'];
        }

        $this->assertSame(3, $byItem['oil']);
        $this->assertSame(4, $byItem['tyres']);
        $this->assertCount(2, $latest);
    }

    /** @param array<string,mixed> $overrides */
    private function job(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'periodic', 'status' => 'done', 'odometer_km' => 10000, 'completed_on' => '2026-01-01',
            'interval_km' => 5000, 'interval_days' => 90,
        ], $overrides);
    }
}
