<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClientUser;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\RateCard;
use App\Models\TripRoute;
use App\Models\TripRouteBudget;
use App\Notifications\ActivateAccountNotification;
use App\Services\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: customers, rate cards, client-portal users (Step 2)
//  Location: tests/Feature/CustomersAndRateCardsTest.php
//  Feature doc: docs/STEP_02_MASTER_DATA.md §3
// ══════════════════════════════════════════════════════════════════

class CustomersAndRateCardsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    /** A route with a 4,880 EGP standard budget (the demo's Obour → Alexandria Port). */
    private function route(int $companyId): TripRoute
    {
        CompanyDefaults::ensure($companyId);
        $route = TripRoute::factory()->create(['company_id' => $companyId]);
        $cats = ExpenseCategory::query()->withoutGlobalScopes()->where('company_id', $companyId)->pluck('id', 'code');
        foreach (['fuel' => 3450, 'toll' => 260, 'weigh' => 120, 'allow' => 600, 'labor' => 450] as $code => $amount) {
            TripRouteBudget::query()->create(['company_id' => $companyId, 'trip_route_id' => $route->id, 'expense_category_id' => $cats[$code], 'amount' => $amount]);
        }

        return $route;
    }

    private function customerData(array $overrides = []): array
    {
        return array_merge(['name_ar' => 'دلتا للصلب', 'name_en' => 'Delta Steel', 'payment_terms_days' => 45, 'may_pay_driver_cash' => false], $overrides);
    }

    public function test_creating_a_customer(): void
    {
        $admin = $this->companyAdmin();

        $this->actingAs($admin, 'web')->post('/office/customers', $this->customerData())->assertRedirect();

        $this->assertSame($admin->company_id, Customer::query()->where('name_en', 'Delta Steel')->value('company_id'));
    }

    public function test_the_rate_card_shows_expected_profit_before_and_after_g_and_a(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $route = $this->route($company->id);
        CompanySetting::for($company->id)->update(['ga_rate_estimate' => 4.5]);
        $customer = Customer::factory()->for($company)->create();

        $this->actingAs($admin, 'web')->post("/office/customers/{$customer->id}/rates", ['trip_route_id' => $route->id, 'price' => 10600])->assertSessionHasNoErrors();

        $this->get("/office/customers?customer={$customer->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Office/Customers/Index')
            ->where('selected.rates.0.standard', 4880)
            ->where('selected.rates.0.direct', 5720)
            ->where('selected.rates.0.after_ga', 3695)); // 5,720 − 450 km × 4.50
    }

    public function test_a_customer_has_one_price_per_route_and_price_changes_are_audited(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $route = $this->route($company->id);
        $customer = Customer::factory()->for($company)->create();

        $this->actingAs($admin, 'web')->post("/office/customers/{$customer->id}/rates", ['trip_route_id' => $route->id, 'price' => 10000]);
        $this->post("/office/customers/{$customer->id}/rates", ['trip_route_id' => $route->id, 'price' => 9000])->assertSessionHasErrors('trip_route_id');

        $rate = RateCard::query()->firstOrFail();
        $this->patch("/office/customers/{$customer->id}/rates/{$rate->id}", ['price' => 10500])->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'rate_card.price_changed')->firstOrFail();
        $this->assertEquals(10000, $log->changes['before']['price']);
        $this->assertEquals(10500, $log->changes['after']['price']);
    }

    public function test_a_route_of_another_company_cannot_be_priced(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $customer = Customer::factory()->for($company)->create();
        $foreignRoute = $this->route($this->company()->id);

        $this->actingAs($admin, 'web')->post("/office/customers/{$customer->id}/rates", ['trip_route_id' => $foreignRoute->id, 'price' => 5000])
            ->assertSessionHasErrors('trip_route_id');
    }

    public function test_inviting_a_client_portal_user_sends_an_activation_link_that_works(): void
    {
        Notification::fake();
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $customer = Customer::factory()->for($company)->create();

        $this->actingAs($admin, 'web')->post("/office/customers/{$customer->id}/clients", [
            'name' => 'Eng. Ashraf', 'email' => 'Ashraf@Client.test', 'language' => 'ar',
        ])->assertSessionHas('success');

        $client = ClientUser::query()->where('email', 'ashraf@client.test')->firstOrFail();
        $this->assertTrue($client->is_account_admin);
        $this->assertNull($client->email_verified_at);

        $token = null;
        Notification::assertSentTo($client, ActivateAccountNotification::class, function ($n) use (&$token, $client) {
            $token = (fn () => $this->token)->call($n);
            $this->assertStringContainsString('for=client', (string) $n->toMail($client)->render());

            return true;
        });

        $this->flushSession();
        auth('web')->logout();
        $this->post('/reset-password', [
            'token' => $token, 'email' => $client->email, 'mode' => 'activate', 'for' => 'client',
            'password' => 'Tara@2026x', 'password_confirmation' => 'Tara@2026x',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('Tara@2026x', $client->fresh()->password));
        $this->post('/login', ['login' => $client->email, 'password' => 'Tara@2026x'])->assertRedirect('/client');
    }

    public function test_a_client_email_cannot_belong_to_an_office_user(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $customer = Customer::factory()->for($company)->create();

        $this->actingAs($admin, 'web')->post("/office/customers/{$customer->id}/clients", ['name' => 'X', 'email' => $admin->email, 'language' => 'ar'])
            ->assertSessionHasErrors('email');
    }

    public function test_customers_and_rates_of_another_company_are_not_found(): void
    {
        $admin = $this->companyAdmin();
        $otherCompany = $this->company();
        $foreign = Customer::factory()->for($otherCompany)->create();

        $this->actingAs($admin, 'web')->patch("/office/customers/{$foreign->id}", $this->customerData())->assertNotFound();
        $this->post("/office/customers/{$foreign->id}/clients", ['name' => 'X', 'email' => 'x@x.test', 'language' => 'ar'])->assertNotFound();
        $this->delete("/office/customers/{$foreign->id}")->assertNotFound();
    }

    public function test_a_customer_with_portal_users_cannot_be_deleted(): void
    {
        $company = $this->company();
        $admin = $this->companyAdmin($company);
        $client = $this->clientUser($company);

        $this->actingAs($admin, 'web')->delete("/office/customers/{$client->customer_id}")->assertSessionHas('error');
        $this->assertNotNull(Customer::query()->find($client->customer_id));
    }
}
