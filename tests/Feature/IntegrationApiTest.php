<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\IntegrationClient;
use App\Models\LineOfBusiness;
use App\Models\Policy;
use App\Models\PolicyEndorsement;
use App\Models\Product;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-INT — inbound integration. The upstream system PUSHes policy/endorsement/
 * renewal events; each lands as a DRAFT, idempotent by [source_system,
 * external_id]. Auth is the integration-key header, not Sanctum.
 */
final class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-integration-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->referenceData();

        $serviceUser = User::factory()->create(['email' => 'svc@diamond.local']);
        IntegrationClient::query()->create([
            'name' => 'Broker Core', 'source_system' => 'broker-core',
            'key_hash' => IntegrationClient::hashKey(self::KEY),
            'acts_as_user_id' => $serviceUser->id, 'is_active' => true,
        ]);
    }

    public function test_push_creates_a_draft_policy_with_external_id(): void
    {
        $res = $this->push('policies', $this->policyPayload());

        $res->assertStatus(201)
            ->assertJsonPath('data.event_type', 'policy')
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.external_id', 'EXT-POL-1');

        $policy = Policy::query()->where('external_id', 'EXT-POL-1')->first();
        $this->assertNotNull($policy);
        $this->assertSame('broker-core', $policy->source_system);
        $this->assertSame('draft', $policy->status->value);
        $this->assertEqualsWithDelta(10000, (float) $policy->net_premium, 0.01);
    }

    public function test_repush_is_idempotent(): void
    {
        $this->push('policies', $this->policyPayload())->assertStatus(201);

        $this->push('policies', $this->policyPayload())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, Policy::query()->where('external_id', 'EXT-POL-1')->count());
    }

    public function test_unknown_customer_code_fails_with_422_and_logs(): void
    {
        $payload = $this->policyPayload();
        $payload['customer_code'] = 'NOPE';

        $this->push('policies', $payload)->assertStatus(422);

        $this->assertDatabaseHas('integration_events', [
            'external_id' => 'EXT-POL-1', 'status' => 'failed',
        ]);
        $this->assertSame(0, Policy::query()->where('external_id', 'EXT-POL-1')->count());
    }

    public function test_missing_key_is_unauthorised(): void
    {
        $this->postJson('/api/v1/integration/policies', $this->policyPayload())
            ->assertStatus(401);
    }

    public function test_push_endorsement_against_issued_policy(): void
    {
        $policy = $this->issuedIngestedPolicy();

        $res = $this->push('endorsements', [
            'external_id' => 'EXT-END-1',
            'policy_external_id' => $policy->external_id,
            'type' => 'addition',
            'delta_net_premium' => 1000,
            'effective_date' => '2026-04-01',
            'reason' => 'mid-term add',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'processed');

        $end = PolicyEndorsement::query()->where('external_id', 'EXT-END-1')->first();
        $this->assertNotNull($end);
        $this->assertSame('draft', $end->status->value);
        $this->assertSame($policy->id, $end->policy_id);
    }

    public function test_push_renewal_clones_into_a_new_draft(): void
    {
        $policy = $this->issuedIngestedPolicy();

        $res = $this->push('renewals', [
            'external_id' => 'EXT-REN-1',
            'policy_external_id' => $policy->external_id,
            'net_premium' => 12000,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.event_type', 'renewal');

        $renewal = Policy::query()->where('external_id', 'EXT-REN-1')->first();
        $this->assertNotNull($renewal);
        $this->assertSame('draft', $renewal->status->value);
        $this->assertSame($policy->id, $renewal->renewed_from_policy_id);
    }

    private function push(string $path, array $body)
    {
        return $this->withHeader('X-Integration-Key', self::KEY)
            ->postJson("/api/v1/integration/{$path}", $body);
    }

    /** @return array<string, mixed> */
    private function policyPayload(): array
    {
        return [
            'external_id' => 'EXT-POL-1',
            'customer_code' => 'CUST-1',
            'insurer_code' => 'INS-1',
            'product_code' => 'PROP',
            'start_date' => '2026-03-01',
            'end_date' => '2027-02-28',
            'net_premium' => 10000,
        ];
    }

    private function issuedIngestedPolicy(): Policy
    {
        return Policy::query()->create([
            'policy_number' => 'POL-1', 'source_system' => 'broker-core', 'external_id' => 'EXT-POL-X',
            'customer_id' => Customer::query()->value('id'), 'insurer_id' => Vendor::query()->value('id'),
            'product_id' => Product::query()->value('id'),
            'start_date' => '2026-03-01', 'end_date' => '2027-02-28', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'payment_method' => 'full', 'tax_code_id' => TaxCode::query()->value('id'),
            'net_premium' => 10000, 'premium_tax_amount' => 1500, 'gross_premium' => 11500,
            'commission_rate' => 15, 'commission_amount' => 1500, 'commission_tax_amount' => 225,
            'status' => 'issued', 'created_by' => User::query()->value('id'),
        ]);
    }

    private function referenceData(): void
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
        Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id, 'created_by' => $u->id]);
        $lob = LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'default_tax_code_id' => $vat15->id, 'commission_revenue_account_id' => $commission->id, 'status' => 'active']);
    }
}
