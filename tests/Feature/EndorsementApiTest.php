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
use App\Models\PolicyEndorsement;
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
 * P4.17 — Policy endorsements: addition/upgrade raise additional premium,
 * deletion/downgrade refund it. Posting writes a delta of the fiduciary entry
 * and adjusts the policy's running totals.
 */
final class EndorsementApiTest extends TestCase
{
    use RefreshDatabase;

    private int $policyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->policyId = $this->issuedPolicy();
    }

    private function issuedPolicy(): int
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $receivable = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Insurer payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $commission = ChartOfAccount::query()->create(['code' => '4100', 'name' => 'Brokerage', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $vatHeader = ChartOfAccount::query()->create(['code' => '223', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        ChartOfAccount::query()->create(['code' => '2230', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 2, 'parent_id' => $vatHeader->id, 'is_postable' => true, 'status' => 'active']);
        $vat15 = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'output_account_id' => $vatHeader->id, 'is_recoverable' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $insurer = Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id, 'created_by' => $u->id]);
        $lob = LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        $product = Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'default_tax_code_id' => $vat15->id, 'commission_revenue_account_id' => $commission->id, 'status' => 'active']);

        return Policy::query()->create([
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

    private function createEndorsement(string $type, float $deltaNet): array
    {
        $this->actingAsRole('accountant');

        return $this->postJson("/api/v1/policies/{$this->policyId}/endorsements", [
            'type' => $type, 'effective_date' => '2026-03-20', 'reason' => 'mid-term change', 'delta_net_premium' => $deltaNet,
        ])->assertCreated()->json('data');
    }

    private function approveAndPost(int $endorsementId): void
    {
        $this->actingAsRole('accountant');
        $this->postJson("/api/v1/policies/endorsements/{$endorsementId}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_type', PolicyEndorsement::class)->where('approvable_id', $endorsementId)->value('id');
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/policies/endorsements/{$endorsementId}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_addition_derives_direction_and_deltas(): void
    {
        $e = $this->createEndorsement('addition', 2000);

        $this->assertSame('additional', $e['direction']);
        $this->assertSame('300.00', $e['delta_premium_tax']);   // 15% of 2000
        $this->assertSame('2300.00', $e['delta_gross']);
        $this->assertSame('300.00', $e['delta_commission']);    // 15% of 2000
        $this->assertSame('45.00', $e['delta_commission_tax']); // 15% of 300
    }

    public function test_downgrade_is_a_refund(): void
    {
        $e = $this->createEndorsement('downgrade', 1000);
        $this->assertSame('refund', $e['direction']);
    }

    public function test_addition_posts_a_positive_delta_and_grows_the_policy(): void
    {
        $e = $this->createEndorsement('addition', 2000);
        $this->approveAndPost($e['id']);

        $rows = GlTransaction::query()->where('source_type', PolicyEndorsement::class)->where('source_id', $e['id'])->get();
        $this->assertCount(5, $rows);
        // AR is debited (customer owes more) by the additional gross 2,300.
        $arLeg = $rows->first(fn ($r) => str_starts_with((string) $r->description, 'Additional premium —'));
        $this->assertEqualsWithDelta(2300, (float) $arLeg->base_debit, 0.01);
        $this->assertEqualsWithDelta(2645, $rows->sum('base_debit'), 0.01); // 2300 + (300+45)
        // Commission revenue must be CREDITED on an addition (broker earns more).
        $brokerage = $rows->first(fn ($r) => str_starts_with((string) $r->description, 'Brokerage'));
        $this->assertEqualsWithDelta(300, (float) $brokerage->base_credit, 0.01);
        $this->assertEqualsWithDelta(0, (float) $brokerage->base_debit, 0.01);

        $policy = Policy::query()->find($this->policyId);
        $this->assertEqualsWithDelta(12000, (float) $policy->net_premium, 0.01);
        $this->assertEqualsWithDelta(13800, (float) $policy->gross_premium, 0.01);
        $this->assertEqualsWithDelta(1800, (float) $policy->commission_amount, 0.01);
    }

    public function test_deletion_posts_a_refund_and_shrinks_the_policy(): void
    {
        $e = $this->createEndorsement('deletion', 2000);
        $this->approveAndPost($e['id']);

        $rows = GlTransaction::query()->where('source_type', PolicyEndorsement::class)->where('source_id', $e['id'])->get();
        // Refund reverses the direction: AR is CREDITED (customer owes less) by 2,300.
        $arLeg = $rows->first(fn ($r) => str_starts_with((string) $r->description, 'Refund premium —'));
        $this->assertEqualsWithDelta(2300, (float) $arLeg->base_credit, 0.01);
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        // Commission revenue is DEBITED on a refund (clawback).
        $brokerage = $rows->first(fn ($r) => str_starts_with((string) $r->description, 'Brokerage'));
        $this->assertEqualsWithDelta(300, (float) $brokerage->base_debit, 0.01);

        $policy = Policy::query()->find($this->policyId);
        $this->assertEqualsWithDelta(8000, (float) $policy->net_premium, 0.01);
        $this->assertEqualsWithDelta(9200, (float) $policy->gross_premium, 0.01);
        $this->assertEqualsWithDelta(1200, (float) $policy->commission_amount, 0.01);
    }

    public function test_endorsement_resyncs_the_installment_schedule(): void
    {
        // Turn the issued policy into an installment policy whose schedule sums to the current gross (11,500).
        $policy = Policy::query()->find($this->policyId);
        $policy->update(['payment_method' => 'installment']);
        foreach ([1, 2, 3, 4] as $seq) {
            $policy->installments()->create([
                'sequence' => $seq,
                'due_date' => '2026-0'.(2 + $seq).'-01',
                'amount' => 2875, // 4 × 2,875 = 11,500
                'amount_collected' => 0,
                'status' => 'unpaid',
            ]);
        }

        // Addition of +2,000 net grows the gross by 2,300 to 13,800.
        $e = $this->createEndorsement('addition', 2000);
        $this->approveAndPost($e['id']);

        $policy->refresh();
        $this->assertEqualsWithDelta(13800, (float) $policy->gross_premium, 0.01);

        // Same number of installments, re-spread to sum to the new gross.
        $installments = $policy->installments()->orderBy('sequence')->get();
        $this->assertCount(4, $installments);
        $this->assertEqualsWithDelta(13800, (float) $installments->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(3450, (float) $installments->first()->amount, 0.01);
    }

    public function test_cannot_endorse_a_draft_policy(): void
    {
        Policy::query()->whereKey($this->policyId)->update(['status' => 'draft']);
        $this->actingAsRole('accountant');

        $this->postJson("/api/v1/policies/{$this->policyId}/endorsements", [
            'type' => 'addition', 'effective_date' => '2026-03-20', 'delta_net_premium' => 1000,
        ])->assertStatus(422);
    }

    public function test_update_draft_endorsement_recomputes_deltas(): void
    {
        $e = $this->createEndorsement('addition', 2000);
        $this->actingAsRole('accountant');

        $updated = $this->putJson("/api/v1/policies/endorsements/{$e['id']}", [
            'type' => 'addition', 'effective_date' => '2026-03-25', 'delta_net_premium' => 4000, 'reason' => 'revised',
        ])->assertOk()->json('data');

        $this->assertSame('4600.00', $updated['delta_gross']); // 4,000 + 15% VAT
        $this->assertSame('revised', $updated['reason']);
    }

    public function test_delete_draft_endorsement(): void
    {
        $e = $this->createEndorsement('addition', 2000);
        $this->actingAsRole('accountant');

        $this->deleteJson("/api/v1/policies/endorsements/{$e['id']}")->assertNoContent();
        $this->assertNull(PolicyEndorsement::query()->find($e['id']));
    }

    public function test_cannot_edit_a_posted_endorsement(): void
    {
        $e = $this->createEndorsement('addition', 2000);
        $this->approveAndPost($e['id']);
        $this->actingAsRole('accountant');

        $this->putJson("/api/v1/policies/endorsements/{$e['id']}", [
            'type' => 'addition', 'effective_date' => '2026-03-25', 'delta_net_premium' => 4000,
        ])->assertStatus(409);
    }
}
