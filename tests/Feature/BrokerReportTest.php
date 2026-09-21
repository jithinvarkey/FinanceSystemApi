<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\LineOfBusiness;
use App\Models\Policy;
use App\Models\PolicyCancellation;
use App\Models\PremiumCollection;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4.17 Slice D (§12) — Insurer bordereaux: per-insurer net position + statement.
 */
final class BrokerReportTest extends TestCase
{
    use RefreshDatabase;

    private int $insurerId;

    private int $policyId;

    private int $payableId;

    private int $userId;

    private int $periodId;

    private int $receivableId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class]);

        $u = User::factory()->create();
        $this->userId = $u->id;
        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Insurer payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $this->payableId = $payable->id;
        $receivable = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $this->receivableId = $receivable->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $this->customerId = $customer->id;
        $insurer = Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id, 'created_by' => $u->id]);
        $this->insurerId = $insurer->id;
        $lob = LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        $product = Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'status' => 'active']);

        // Issued policy: net 10,000 / gross 11,500 / commission 1,500 + VAT 225;
        // net due to insurer = 9,775; 5,000 already settled → 4,775 outstanding.
        $this->policyId = Policy::query()->create([
            'policy_number' => 'POL-1', 'customer_id' => $customer->id, 'insurer_id' => $insurer->id, 'product_id' => $product->id,
            'start_date' => '2026-03-01', 'end_date' => '2027-02-28', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'payment_method' => 'full', 'net_premium' => 10000, 'premium_tax_amount' => 1500, 'gross_premium' => 11500,
            'commission_rate' => 15, 'commission_amount' => 1500, 'commission_tax_amount' => 225,
            'premium_collected' => 0, 'insurer_settled' => 5000,
            'status' => 'issued', 'created_by' => $u->id,
        ])->id;
    }

    private function glRow(string $batch, string $date, string $sourceType, int $sourceId, float $debit, float $credit, ?int $accountId = null): void
    {
        DB::table('gl_transactions')->insert([
            'batch_number' => $batch, 'fiscal_period_id' => $this->periodId, 'transaction_date' => $date,
            'account_id' => $accountId ?? $this->payableId, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'debit' => $debit, 'credit' => $credit, 'base_debit' => $debit, 'base_credit' => $credit,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'posted_by' => $this->userId,
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actingAsViewer(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'auditor')->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_insurer_positions_reconcile_the_net_position(): void
    {
        $this->actingAsViewer();

        $row = $this->getJson('/api/v1/policies/reports/insurer-positions')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertSame('Tawuniya', $row['insurer_name']);
        $this->assertEqualsWithDelta(11500, (float) $row['gross_premium'], 0.01);
        $this->assertEqualsWithDelta(1725, (float) $row['commission'], 0.01);   // 1,500 + 225
        $this->assertEqualsWithDelta(9775, (float) $row['net_due'], 0.01);       // 11,500 − 1,725
        $this->assertEqualsWithDelta(5000, (float) $row['settled'], 0.01);
        $this->assertEqualsWithDelta(4775, (float) $row['outstanding'], 0.01);   // 9,775 − 5,000
    }

    public function test_insurer_statement_is_a_transaction_ledger_with_issuance_and_cancellation_as_separate_lines(): void
    {
        // Issuance raises the payable by 9,775; a later cancellation refund reduces it by 4,000.
        $cancellation = PolicyCancellation::query()->create([
            'cancellation_number' => 'CANC-1', 'policy_id' => $this->policyId, 'method' => 'pro_rata',
            'cancellation_date' => '2026-04-01', 'policy_days' => 365, 'days_on_risk' => 31,
            'refund_net' => 3478, 'refund_premium_tax' => 522, 'refund_gross' => 4000,
            'clawback_commission' => 0, 'clawback_commission_tax' => 0,
            'status' => 'posted', 'created_by' => $this->userId,
        ]);

        $this->glRow('GLB-1', '2026-03-01', Policy::class, $this->policyId, 0, 9775);
        $this->glRow('GLB-2', '2026-04-01', PolicyCancellation::class, $cancellation->id, 4000, 0);

        $this->actingAsViewer();

        $data = $this->getJson("/api/v1/policies/reports/insurer-statement/{$this->insurerId}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['entries']);

        $this->assertSame('issuance', $data['entries'][0]['type']);
        $this->assertSame('POL-1', $data['entries'][0]['reference']);
        $this->assertSame('POL-1', $data['entries'][0]['policy_number']);
        $this->assertSame('Gulf', $data['entries'][0]['customer_name']);
        $this->assertEqualsWithDelta(9775, (float) $data['entries'][0]['credit'], 0.01);
        $this->assertEqualsWithDelta(9775, (float) $data['entries'][0]['balance'], 0.01);

        $this->assertSame('cancellation', $data['entries'][1]['type']);
        $this->assertSame('CANC-1', $data['entries'][1]['reference']);
        $this->assertSame('POL-1', $data['entries'][1]['policy_number']);
        $this->assertSame('Gulf', $data['entries'][1]['customer_name']);
        $this->assertEqualsWithDelta(4000, (float) $data['entries'][1]['debit'], 0.01);
        $this->assertEqualsWithDelta(5775, (float) $data['entries'][1]['balance'], 0.01);

        $this->assertEqualsWithDelta(5775, (float) $data['closing_balance'], 0.01);
    }

    public function test_customer_aging_buckets_outstanding_premium(): void
    {
        // Full-pay policy, nothing collected → 11,500 outstanding, aged from start (2026-03-01).
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/policies/reports/customer-aging?as_of=2026-06-15')
            ->assertOk()
            ->json('data');

        $row = $data['rows'][0];
        $this->assertSame('POL-1', $row['policy_number']);
        $this->assertSame('Gulf', $row['customer_name']);
        $this->assertSame('Tawuniya', $row['insurer_name']);
        $this->assertEqualsWithDelta(11500, (float) $row['outstanding'], 0.01);
        $this->assertEqualsWithDelta(11500, (float) $row['91_120'], 0.01); // 106 days
        $this->assertEqualsWithDelta(11500, (float) $data['totals']['outstanding'], 0.01);
    }

    public function test_insurer_aging_buckets_outstanding_net_premium(): void
    {
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/policies/reports/insurer-aging?as_of=2026-06-15')
            ->assertOk()
            ->json('data');

        $row = $data['rows'][0];
        $this->assertSame('POL-1', $row['policy_number']);
        $this->assertSame('Tawuniya', $row['insurer_name']);
        $this->assertEqualsWithDelta(4775, (float) $row['outstanding'], 0.01);  // 9,775 − 5,000 settled
        $this->assertEqualsWithDelta(4775, (float) $row['91_120'], 0.01);
    }

    public function test_customer_statement_is_a_receivable_ledger(): void
    {
        $collection = PremiumCollection::query()->create([
            'collection_number' => 'PCOL-1', 'policy_id' => $this->policyId, 'collection_date' => '2026-04-01',
            'bank_account_id' => $this->receivableId, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'payment_method' => 'bank_transfer', 'amount' => 5000, 'status' => 'posted', 'created_by' => $this->userId,
        ]);

        // Issuance debits AR 11,500; a collection credits AR 5,000 → 6,500 still owed.
        $this->glRow('GLB-1', '2026-03-01', Policy::class, $this->policyId, 11500, 0, $this->receivableId);
        $this->glRow('GLB-3', '2026-04-01', PremiumCollection::class, $collection->id, 0, 5000, $this->receivableId);

        $this->actingAsViewer();

        $data = $this->getJson("/api/v1/policies/reports/customer-statement/{$this->customerId}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['entries']);

        // SOA layout: INS issuance line.
        $this->assertSame('INS', $data['entries'][0]['type']);
        $this->assertSame('POL-1', $data['entries'][0]['policy_number']);
        $this->assertSame('Tawuniya', $data['entries'][0]['insurer_name']);
        $this->assertSame('Gulf', $data['entries'][0]['client_name']);
        $this->assertSame('New policy issued', $data['entries'][0]['description']);
        $this->assertEqualsWithDelta(11500, (float) $data['entries'][0]['debit'], 0.01);
        $this->assertEqualsWithDelta(11500, (float) $data['entries'][0]['balance'], 0.01);

        // PMT payment line.
        $this->assertSame('PMT', $data['entries'][1]['type']);
        $this->assertSame('Payment Received', $data['entries'][1]['description']);
        $this->assertEqualsWithDelta(5000, (float) $data['entries'][1]['credit'], 0.01);
        $this->assertEqualsWithDelta(6500, (float) $data['entries'][1]['balance'], 0.01);

        $this->assertEqualsWithDelta(11500, (float) $data['totals']['debit'], 0.01);
        $this->assertEqualsWithDelta(5000, (float) $data['totals']['credit'], 0.01);
        $this->assertEqualsWithDelta(6500, (float) $data['closing_balance'], 0.01);
    }
}
