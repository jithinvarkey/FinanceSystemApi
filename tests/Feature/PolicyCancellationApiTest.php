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
use App\Models\PolicyCancellation;
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
 * P4.17 Slice C (§10) — Policy cancellation: refund the unearned premium and
 * claw back the matching commission, then cancel the policy.
 */
final class PolicyCancellationApiTest extends TestCase
{
    use RefreshDatabase;

    private int $policyId;

    private int $receivableId;

    private int $payableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->issuedPolicy();
    }

    private function issuedPolicy(): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $receivable = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Insurer payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $commission = ChartOfAccount::query()->create(['code' => '4100', 'name' => 'Brokerage', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $vatHeader = ChartOfAccount::query()->create(['code' => '223', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $vat15 = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'output_account_id' => $vatHeader->id, 'is_recoverable' => true, 'status' => 'active']);
        $this->receivableId = $receivable->id;
        $this->payableId = $payable->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        foreach ([3, 9] as $p) {
            FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => $p, 'name' => "P{$p} 2026", 'start_date' => "2026-0{$p}-01", 'end_date' => "2026-0{$p}-28", 'status' => 'open']);
        }

        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $insurer = Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id, 'created_by' => $u->id]);
        $lob = LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        $product = Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'default_tax_code_id' => $vat15->id, 'commission_revenue_account_id' => $commission->id, 'status' => 'active']);

        $this->policyId = Policy::query()->create([
            'policy_number' => 'POL-1', 'customer_id' => $customer->id, 'insurer_id' => $insurer->id, 'product_id' => $product->id,
            'start_date' => '2026-03-01', 'end_date' => '2027-02-28', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'payment_method' => 'full', 'tax_code_id' => $vat15->id,
            'net_premium' => 10000, 'premium_tax_amount' => 1500, 'gross_premium' => 11500,
            'commission_rate' => 15, 'commission_amount' => 1500, 'commission_tax_amount' => 225,
            'status' => 'issued', 'created_by' => $u->id,
        ])->id;
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    /** @param array<string, mixed> $payload */
    private function createCancellation(array $payload): array
    {
        $this->actingAsRole('accountant');

        return $this->postJson("/api/v1/policies/{$this->policyId}/cancellations", array_merge([
            'cancellation_date' => '2026-03-01', 'reason' => 'customer request',
        ], $payload))->assertCreated()->json('data');
    }

    private function approveAndPost(int $id): void
    {
        $this->actingAsRole('accountant');
        $this->postJson("/api/v1/policies/cancellations/{$id}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_type', PolicyCancellation::class)->where('approvable_id', $id)->value('id');
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/policies/cancellations/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_pro_rata_at_inception_refunds_the_full_premium(): void
    {
        // Cancelling on the start date = the whole term is unearned → full refund.
        $c = $this->createCancellation(['method' => 'pro_rata', 'cancellation_date' => '2026-03-01']);

        $this->assertSame(0, $c['days_on_risk']);
        $this->assertSame('11500.00', $c['refund_gross']);
        $this->assertSame('1500.00', $c['clawback_commission']);
        $this->assertSame('225.00', $c['clawback_commission_tax']);

        $this->approveAndPost($c['id']);

        $rows = GlTransaction::query()->where('source_type', PolicyCancellation::class)->where('source_id', $c['id'])->get();
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        // Customer receivable is credited (refund owed); commission revenue is debited (clawback).
        $arLeg = $rows->firstWhere('account_id', $this->receivableId);
        $this->assertEqualsWithDelta(11500, (float) $arLeg->base_credit, 0.01);

        $policy = Policy::query()->find($this->policyId);
        $this->assertSame('cancelled', $policy->status->value);
        $this->assertEqualsWithDelta(0, (float) $policy->gross_premium, 0.01);
        $this->assertEqualsWithDelta(0, (float) $policy->commission_amount, 0.01);
    }

    public function test_short_rate_keeps_a_penalty(): void
    {
        // Short-rate at inception with a 10% penalty → refund 90% of the unearned net.
        $c = $this->createCancellation(['method' => 'short_rate', 'short_rate_penalty' => 10, 'cancellation_date' => '2026-03-01']);

        $this->assertSame('9000.00', $c['refund_net']);     // 10,000 × 0.9
        $this->assertSame('1350.00', $c['clawback_commission']); // 1,500 × 0.9
    }

    public function test_mid_term_pro_rata_is_partial_and_balanced(): void
    {
        $c = $this->createCancellation(['method' => 'pro_rata', 'cancellation_date' => '2026-09-01']);

        $this->assertGreaterThan(0, $c['days_on_risk']);
        $this->assertGreaterThan(0, (float) $c['refund_gross']);
        $this->assertLessThan(11500, (float) $c['refund_gross']);

        $this->approveAndPost($c['id']);
        $rows = GlTransaction::query()->where('source_type', PolicyCancellation::class)->where('source_id', $c['id'])->get();
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        $this->assertSame('cancelled', Policy::query()->find($this->policyId)->status->value);
    }

    public function test_cannot_cancel_a_draft_policy(): void
    {
        Policy::query()->whereKey($this->policyId)->update(['status' => 'draft']);
        $this->actingAsRole('accountant');

        $this->postJson("/api/v1/policies/{$this->policyId}/cancellations", [
            'method' => 'pro_rata', 'cancellation_date' => '2026-03-01',
        ])->assertStatus(422);
    }

    public function test_update_draft_cancellation_recomputes_the_refund(): void
    {
        $c = $this->createCancellation(['method' => 'pro_rata', 'cancellation_date' => '2026-03-01']);
        $this->actingAsRole('accountant');

        // Switch to short-rate with a 10% penalty → refund net = 10,000 × 0.9.
        $updated = $this->putJson("/api/v1/policies/cancellations/{$c['id']}", [
            'method' => 'short_rate', 'short_rate_penalty' => 10, 'cancellation_date' => '2026-03-01',
        ])->assertOk()->json('data');

        $this->assertSame('short_rate', $updated['method']);
        $this->assertSame('9000.00', $updated['refund_net']);
    }

    public function test_delete_draft_cancellation(): void
    {
        $c = $this->createCancellation(['method' => 'pro_rata', 'cancellation_date' => '2026-03-01']);
        $this->actingAsRole('accountant');

        $this->deleteJson("/api/v1/policies/cancellations/{$c['id']}")->assertNoContent();
        $this->assertNull(PolicyCancellation::query()->find($c['id']));
    }
}
