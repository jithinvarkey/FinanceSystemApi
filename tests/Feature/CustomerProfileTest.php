<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E2 — Customer 360 profile + activity log.
 */
final class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $ar = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $u = User::factory()->create();
        $this->customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'credit_limit' => 50000, 'created_by' => $u->id]);

        CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $this->customer->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 2000, 'tax_amount' => 0, 'total_amount' => 2000, 'amount_paid' => 500,
            'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);
    }

    private function actAs(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());

        return $u;
    }

    public function test_profile_aggregates_kpis_and_aging(): void
    {
        $this->actAs('accountant');

        $data = $this->getJson("/api/v1/accounts-receivable/customers/{$this->customer->id}/profile")->assertOk()->json('data');

        $this->assertEqualsWithDelta(1500, $data['kpis']['outstanding'], 0.01);      // 2000 − 500
        $this->assertEqualsWithDelta(48500, $data['kpis']['credit_available'], 0.01); // 50000 − 1500
        $this->assertSame(1, $data['kpis']['invoices_count']);
        $this->assertEqualsWithDelta(1500, $data['aging']['current'], 0.01);
    }

    public function test_collection_activity_can_be_logged(): void
    {
        $this->actAs('accountant');

        $this->postJson("/api/v1/accounts-receivable/customers/{$this->customer->id}/activities", [
            'activity_type' => 'call', 'note' => 'Customer promised payment by Friday.',
        ])->assertCreated();

        $data = $this->getJson("/api/v1/accounts-receivable/customers/{$this->customer->id}/profile")->json('data');
        $this->assertCount(1, $data['activities']);
        $this->assertSame('call', $data['activities'][0]['activity_type']);
    }
}
