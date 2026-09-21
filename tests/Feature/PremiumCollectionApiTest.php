<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\LineOfBusiness;
use App\Models\Policy;
use App\Models\PremiumCollection;
use App\Models\Product;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4.17 Slice B (§8.3) — Premium collection: Dr bank → Cr customer receivable,
 * credit the policy's premium_collected and any allocated installments.
 */
final class PremiumCollectionApiTest extends TestCase
{
    use RefreshDatabase;

    private int $policyId;

    private int $bankId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->issuedPolicy();
    }

    private function issuedPolicy(string $paymentMethod = 'full'): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $receivable = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Insurer payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $commission = ChartOfAccount::query()->create(['code' => '4100', 'name' => 'Brokerage', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $bank = ChartOfAccount::query()->create(['code' => '1101', 'name' => 'Bank IBA', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $vat15 = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'output_account_id' => $payable->id, 'is_recoverable' => true, 'status' => 'active']);
        $this->bankId = $bank->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $insurer = Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id, 'created_by' => $u->id]);
        $lob = LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        $product = Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'default_tax_code_id' => $vat15->id, 'commission_revenue_account_id' => $commission->id, 'status' => 'active']);

        $policy = Policy::query()->create([
            'policy_number' => 'POL-1', 'customer_id' => $customer->id, 'insurer_id' => $insurer->id, 'product_id' => $product->id,
            'start_date' => '2026-03-01', 'end_date' => '2027-02-28', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'payment_method' => $paymentMethod, 'tax_code_id' => $vat15->id,
            'net_premium' => 10000, 'premium_tax_amount' => 1500, 'gross_premium' => 11500,
            'commission_rate' => 15, 'commission_amount' => 1500, 'commission_tax_amount' => 225,
            'status' => 'issued', 'created_by' => $u->id,
        ]);
        $this->policyId = $policy->id;
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    /** @param array<string, mixed> $payload */
    private function createCollection(array $payload): array
    {
        $this->actingAsRole('accountant');

        return $this->postJson('/api/v1/policies/premium-collections', array_merge([
            'policy_id' => $this->policyId,
            'collection_date' => '2026-03-15',
            'bank_account_id' => $this->bankId,
        ], $payload))->assertCreated()->json('data');
    }

    private function approveAndPost(int $id): void
    {
        $this->actingAsRole('accountant');
        $this->postJson("/api/v1/policies/premium-collections/{$id}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_type', PremiumCollection::class)->where('approvable_id', $id)->value('id');
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/policies/premium-collections/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_collection_posts_dr_bank_cr_receivable_and_clears_the_policy(): void
    {
        $c = $this->createCollection(['amount' => 11500]);
        $this->approveAndPost($c['id']);

        $rows = GlTransaction::query()->where('source_type', PremiumCollection::class)->where('source_id', $c['id'])->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(11500, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);

        $bankLeg = $rows->firstWhere('account_id', $this->bankId);
        $this->assertEqualsWithDelta(11500, (float) $bankLeg->base_debit, 0.01);

        $policy = Policy::query()->find($this->policyId);
        $this->assertEqualsWithDelta(11500, (float) $policy->premium_collected, 0.01);
        $this->assertEqualsWithDelta(0, $policy->premiumBalanceDue(), 0.01);
    }

    public function test_cannot_collect_more_than_outstanding(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/policies/premium-collections', [
            'policy_id' => $this->policyId,
            'collection_date' => '2026-03-15',
            'bank_account_id' => $this->bankId,
            'amount' => 12000, // exceeds gross 11,500
        ])->assertStatus(422);
    }

    public function test_installment_collection_credits_the_installment(): void
    {
        // Rebuild the policy as installment with a 4-row schedule (4 × 2,875 = 11,500).
        Policy::query()->find($this->policyId)->update(['payment_method' => 'installment']);
        $policy = Policy::query()->find($this->policyId);
        foreach ([1, 2, 3, 4] as $seq) {
            $policy->installments()->create([
                'sequence' => $seq, 'due_date' => '2026-0'.(2 + $seq).'-01',
                'amount' => 2875, 'amount_collected' => 0, 'status' => 'unpaid',
            ]);
        }
        $first = $policy->installments()->orderBy('sequence')->first();

        $c = $this->createCollection([
            'allocations' => [['policy_installment_id' => $first->id, 'amount' => 2875]],
        ]);
        $this->assertEqualsWithDelta(2875, (float) $c['amount'], 0.01);
        $this->approveAndPost($c['id']);

        $first->refresh();
        $this->assertEqualsWithDelta(2875, (float) $first->amount_collected, 0.01);
        $this->assertSame('paid', $first->status);
        $this->assertEqualsWithDelta(2875, (float) Policy::query()->find($this->policyId)->premium_collected, 0.01);
    }
}
