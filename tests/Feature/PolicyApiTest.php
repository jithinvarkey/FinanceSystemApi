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
 * P4.17 — Policy: derive premium/commission/VAT, approve → issue the fiduciary
 * dual entry (Dr AR / Cr insurer payable; Dr insurer payable / Cr commission
 * revenue + output VAT). Life business posts no VAT legs.
 */
final class PolicyApiTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $insurerId;
    private int $generalProductId;
    private int $lifeProductId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->buildFixture();
    }

    private function buildFixture(): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $receivable = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR control', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Insurer payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $commission = ChartOfAccount::query()->create(['code' => '4100', 'name' => 'Brokerage income', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        // Output VAT as a header + postable leaf (exercise resolvePostable).
        $vatHeader = ChartOfAccount::query()->create(['code' => '223', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        ChartOfAccount::query()->create(['code' => '2230', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 2, 'parent_id' => $vatHeader->id, 'is_postable' => true, 'status' => 'active']);

        $vat15 = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'Saudi VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'output_account_id' => $vatHeader->id, 'is_recoverable' => true, 'status' => 'active']);
        $exempt = TaxCode::query()->create(['code' => 'EXEMPT', 'name' => 'Exempt', 'tax_type' => 'both', 'rate' => 0.0, 'is_recoverable' => false, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $creator = User::factory()->create();
        $this->customerId = Customer::query()->create([
            'customer_code' => 'CUST-2026-000001', 'name' => 'Gulf Logistics', 'customer_type' => 'corporate',
            'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id,
            'payment_terms_days' => 30, 'created_by' => $creator->id,
        ])->id;

        $this->insurerId = Vendor::query()->create([
            'vendor_code' => 'VEND-2026-000001', 'name' => 'Tawuniya', 'vendor_type' => 'insurer',
            'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id,
            'payment_terms_days' => 30, 'created_by' => $creator->id,
        ])->id;

        $general = LineOfBusiness::query()->create(['code' => 'GENERAL', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        $life = LineOfBusiness::query()->create(['code' => 'LIFE', 'name' => 'Life', 'is_life' => true, 'status' => 'active']);

        $this->generalProductId = Product::query()->create([
            'lob_id' => $general->id, 'code' => 'GEN-PROP', 'name' => 'Property', 'default_commission_rate' => 15.0,
            'default_tax_code_id' => $vat15->id, 'commission_revenue_account_id' => $commission->id, 'status' => 'active',
        ])->id;
        $this->lifeProductId = Product::query()->create([
            'lob_id' => $life->id, 'code' => 'LIFE-TERM', 'name' => 'Term Life', 'default_commission_rate' => 15.0,
            'default_tax_code_id' => $exempt->id, 'commission_revenue_account_id' => $commission->id, 'status' => 'active',
        ])->id;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customerId,
            'insurer_id' => $this->insurerId,
            'product_id' => $this->generalProductId,
            'start_date' => '2026-03-10',
            'end_date' => '2027-03-09',
            'net_premium' => 10000,
        ], $overrides);
    }

    public function test_create_derives_premium_commission_and_vat(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/policies', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.net_premium', '10000.00')
            ->assertJsonPath('data.premium_tax_amount', '1500.00')
            ->assertJsonPath('data.gross_premium', '11500.00')
            ->assertJsonPath('data.commission_amount', '1500.00')
            ->assertJsonPath('data.commission_tax_amount', '225.00')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_insurer_must_be_an_insurer_vendor(): void
    {
        $this->actingAsRole('accountant');
        $supplier = Vendor::query()->create(['vendor_code' => 'VEND-2026-000099', 'name' => 'Acme', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'created_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/policies', $this->payload(['insurer_id' => $supplier->id]))
            ->assertStatus(422)->assertJsonValidationErrors('insurer_id');
    }

    public function test_approve_then_issue_posts_the_fiduciary_dual_entry(): void
    {
        $this->actingAsRole('accountant');
        $policy = $this->postJson('/api/v1/policies', $this->payload())->json('data');

        $this->postJson("/api/v1/policies/{$policy['id']}/submit")
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $requestId = ApprovalRequest::query()->where('approvable_type', Policy::class)->where('approvable_id', $policy['id'])->value('id');

        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/policies/{$policy['id']}/issue")
            ->assertOk()->assertJsonPath('data.status', 'issued');

        $rows = GlTransaction::query()->where('source_type', Policy::class)->where('source_id', $policy['id'])->get();
        // Dr AR 11500, Cr insurer payable 11500, Dr insurer payable 1725, Cr commission 1500, Cr output VAT 225
        $this->assertCount(5, $rows);
        $this->assertEqualsWithDelta(13225, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(13225, $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(225, $rows->firstWhere('description', 'Output VAT on commission')->base_credit, 0.01);
        $this->assertEqualsWithDelta(1500, $rows->firstWhere('description', 'Brokerage '.$policy['policy_number'])->base_credit, 0.01);
    }

    public function test_life_policy_posts_no_vat_legs(): void
    {
        $this->actingAsRole('accountant');
        $policy = $this->postJson('/api/v1/policies', $this->payload(['product_id' => $this->lifeProductId]))
            ->assertCreated()
            ->assertJsonPath('data.premium_tax_amount', '0.00')
            ->assertJsonPath('data.commission_tax_amount', '0.00')
            ->json('data');

        $this->postJson("/api/v1/policies/{$policy['id']}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_type', Policy::class)->where('approvable_id', $policy['id'])->value('id');
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/policies/{$policy['id']}/issue")->assertOk();

        $rows = GlTransaction::query()->where('source_type', Policy::class)->where('source_id', $policy['id'])->get();
        // Dr AR 10000, Cr insurer payable 10000, Dr insurer payable 1500, Cr commission 1500 — no VAT.
        $this->assertCount(4, $rows);
        $this->assertNull($rows->firstWhere('description', 'Output VAT on commission'));
        $this->assertEqualsWithDelta(11500, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(11500, $rows->sum('base_credit'), 0.01);
    }

    public function test_installment_policy_generates_a_schedule_summing_to_gross(): void
    {
        $this->actingAsRole('accountant');

        $policy = $this->postJson('/api/v1/policies', $this->payload([
            'net_premium' => 12000, 'payment_method' => 'installment', 'installment_count' => 4,
        ]))->assertCreated()
            ->assertJsonPath('data.gross_premium', '13800.00') // 12000 + 15% VAT
            ->assertJsonPath('data.installment_count', 4)
            ->json('data');

        $amounts = array_map(fn ($i) => (float) $i['amount'], $policy['installments']);
        $this->assertCount(4, $amounts);
        $this->assertEqualsWithDelta(13800, array_sum($amounts), 0.01);
        // First installment due on the start date; monthly thereafter.
        $this->assertSame('2026-03-10', $policy['installments'][0]['due_date']);
        $this->assertSame('2026-06-10', $policy['installments'][3]['due_date']);
    }

    public function test_per_policy_commission_overrides_the_product_default(): void
    {
        $this->actingAsRole('accountant');

        // Product default is 15%; override to 8% on this policy.
        $this->postJson('/api/v1/policies', $this->payload(['commission_rate' => 8]))
            ->assertCreated()
            ->assertJsonPath('data.commission_rate', '8.0000')
            ->assertJsonPath('data.commission_amount', '800.00'); // 10000 × 8%
    }

    public function test_full_payment_policy_has_no_installments(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/policies', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.installment_count', 0);
    }

    public function test_cannot_issue_an_unapproved_policy(): void
    {
        $this->actingAsRole('accountant');
        $policy = $this->postJson('/api/v1/policies', $this->payload())->json('data');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/policies/{$policy['id']}/issue")->assertStatus(409);
    }
}
