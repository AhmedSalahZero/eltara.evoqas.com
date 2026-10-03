<?php

namespace Tests\Feature;

use App\Models\GaEntry;
use App\Services\Closing\MonthCloseService;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTrips;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: a standard G&A line exists once per month
//  Location: tests/Feature/GaEntriesUniqueTest.php
// ══════════════════════════════════════════════════════════════════

class GaEntriesUniqueTest extends TestCase
{
    use BuildsTrips, CreatesAccounts, RefreshDatabase;

    private function service(callable $fn): mixed
    {
        return Tenant::forCompany($this->co->id, fn () => $fn(app(MonthCloseService::class)));
    }

    public function test_adding_a_standard_line_again_adds_to_the_existing_one(): void
    {
        $this->setUpFleet();
        $month = Carbon::parse('2026-08-01');

        $this->service(fn ($s) => $s->addLine($month, ['code' => 'rent', 'amount' => 3000], $this->admin));
        $this->service(fn ($s) => $s->addLine($month, ['code' => 'rent', 'amount' => 500], $this->admin));

        $rows = GaEntry::query()->withoutGlobalScopes()->where('code', 'rent')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(3500, $rows[0]->amount);
    }

    public function test_lines_with_their_own_name_can_repeat(): void
    {
        $this->setUpFleet();
        $month = Carbon::parse('2026-08-01');

        $this->service(fn ($s) => $s->addLine($month, ['label' => 'Cleaning', 'amount' => 100], $this->admin));
        $this->service(fn ($s) => $s->addLine($month, ['label' => 'Cleaning', 'amount' => 100], $this->admin));

        $this->assertSame(2, GaEntry::query()->withoutGlobalScopes()->whereNull('code')->count());
    }

    public function test_the_same_standard_line_in_another_month_is_fine(): void
    {
        $this->setUpFleet();

        $this->service(fn ($s) => $s->addLine(Carbon::parse('2026-07-01'), ['code' => 'rent', 'amount' => 3000], $this->admin));
        $this->service(fn ($s) => $s->addLine(Carbon::parse('2026-08-01'), ['code' => 'rent', 'amount' => 3000], $this->admin));

        $this->assertSame(2, GaEntry::query()->withoutGlobalScopes()->where('code', 'rent')->count());
    }

    public function test_an_edit_cannot_turn_a_line_into_a_standard_line_that_already_exists(): void
    {
        $this->setUpFleet();
        $month = Carbon::parse('2026-08-01');

        $this->service(fn ($s) => $s->addLine($month, ['code' => 'rent', 'amount' => 3000], $this->admin));
        $own = $this->service(fn ($s) => $s->addLine($month, ['label' => 'Cleaning', 'amount' => 100], $this->admin));

        $this->expectException(\App\Services\Trips\TripRuleException::class);
        $this->service(fn ($s) => $s->updateLine($own, ['code' => 'rent', 'amount' => 100], $this->admin));
    }

    public function test_the_database_itself_refuses_a_second_row(): void
    {
        $this->setUpFleet();
        $row = ['company_id' => $this->co->id, 'month' => '2026-08-01', 'code' => 'rent', 'label' => 'Rent', 'amount' => 10, 'created_at' => now(), 'updated_at' => now()];

        \Illuminate\Support\Facades\DB::table('ga_entries')->insert($row);

        $this->expectException(QueryException::class);
        \Illuminate\Support\Facades\DB::table('ga_entries')->insert($row);
    }
}
