<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\LineOfBusiness;
use App\Models\Policy;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * N1 — Premium installment management.
 */
final class PremiumInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private int $policyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $u = User::factory()->create();
        $ar = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $ap = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Insurer payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $insurer = Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $ap, 'created_by' => $u->id]);
        $lob = LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        $product = Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'status' => 'active']);

        $this->policyId = Policy::query()->create([
            'policy_number' => 'POL-1', 'customer_id' => $customer->id, 'insurer_id' => $insurer->id, 'product_id' => $product->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'payment_method' => 'full', 'net_premium' => 10000, 'premium_tax_amount' => 1500, 'gross_premium' => 12000,
            'commission_rate' => 15, 'commission_amount' => 1500, 'commission_tax_amount' => 225,
            'premium_collected' => 0, 'insurer_settled' => 0, 'status' => 'issued', 'created_by' => $u->id,
        ])->id;
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_plan_splits_premium_into_dated_installments(): void
    {
        $this->actAs('accountant');

        $plan = $this->postJson('/api/v1/policies/installments', [
            'policy_id' => $this->policyId, 'installments' => 4, 'frequency' => 'quarterly', 'start_date' => '2026-01-15',
        ])->assertCreated()->json('data');

        $this->assertCount(4, $plan['items']);
        $this->assertEqualsWithDelta(12000, collect($plan['items'])->sum(fn ($i) => (float) $i['amount']), 0.01);
        $this->assertEqualsWithDelta(3000, (float) $plan['items'][0]['amount'], 0.01);
        // Quarterly spacing: installment 2 due 3 months after the first.
        $this->assertStringStartsWith('2026-04-15', (string) $plan['items'][1]['due_date']);
    }

    public function test_recording_payments_marks_installment_paid_and_completes_plan(): void
    {
        $this->actAs('accountant');

        $plan = $this->postJson('/api/v1/policies/installments', [
            'policy_id' => $this->policyId, 'installments' => 2, 'frequency' => 'monthly', 'start_date' => '2026-01-15', 'total_amount' => 1000,
        ])->assertCreated()->json('data');

        $first = $plan['items'][0]['id'];
        $second = $plan['items'][1]['id'];

        // Partial then full on the first installment.
        $this->postJson("/api/v1/policies/installments/{$first}/payment", ['amount' => 200])->assertOk()->assertJsonPath('data.status', 'partial');
        $this->postJson("/api/v1/policies/installments/{$first}/payment", ['amount' => 300])->assertOk()->assertJsonPath('data.status', 'paid');

        // Overpay rejected.
        $this->postJson("/api/v1/policies/installments/{$second}/payment", ['amount' => 999])->assertStatus(422);

        // Pay the rest → plan completes.
        $this->postJson("/api/v1/policies/installments/{$second}/payment", ['amount' => 500])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('premium_installment_plans', ['id' => $plan['id'], 'status' => 'completed']);
    }

    public function test_due_report_separates_overdue_from_upcoming(): void
    {
        $this->actAs('accountant');

        $this->postJson('/api/v1/policies/installments', [
            'policy_id' => $this->policyId, 'installments' => 3, 'frequency' => 'monthly', 'start_date' => '2026-01-15', 'total_amount' => 3000,
        ])->assertCreated();

        // As of 2026-02-01: Jan 15 instalment is overdue, Feb 15 is upcoming (within 30 days).
        $report = $this->getJson('/api/v1/policies/installments/due?as_of=2026-02-01&horizon_days=30')->assertOk()->json('data');

        $this->assertCount(1, $report['overdue']);
        $this->assertEqualsWithDelta(1000, $report['overdue_total'], 0.01);
        $this->assertCount(1, $report['upcoming']);
    }
}
