<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Lease;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E14 — IFRS 16 leases + IFRS 9 ECL provision matrix.
 */
final class LeaseAndEclTest extends TestCase
{
    use RefreshDatabase;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        foreach ([
            'rou' => ['1410', 'ROU asset', 'asset', 'debit'],
            'liab' => ['2410', 'Lease liability', 'liability', 'credit'],
            'interest' => ['5410', 'Lease interest', 'expense', 'debit'],
            'deprec' => ['5420', 'ROU depreciation', 'expense', 'debit'],
            'bank' => ['1001', 'Bank', 'asset', 'debit'],
            'ecl_exp' => ['5500', 'ECL impairment', 'expense', 'debit'],
            'allowance' => ['1290', 'ECL allowance', 'asset', 'credit'],
        ] as $k => [$code, $name, $type, $nb]) {
            $this->acc[$k] = ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $nb, 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        }

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        foreach (range(1, 12) as $m) {
            FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => $m, 'name' => 'M'.$m, 'start_date' => sprintf('2026-%02d-01', $m), 'end_date' => date('Y-m-t', strtotime(sprintf('2026-%02d-01', $m))), 'status' => 'open']);
        }
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_lease_recognises_rou_and_liability_then_unwinds_monthly(): void
    {
        $this->actAs('finance-manager');

        $lease = $this->postJson('/api/v1/general-ledger/leases', [
            'description' => 'Head office', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'monthly_payment' => 1000, 'discount_rate' => 6,
            'rou_asset_account_id' => $this->acc['rou'], 'lease_liability_account_id' => $this->acc['liab'],
            'interest_expense_account_id' => $this->acc['interest'], 'depreciation_expense_account_id' => $this->acc['deprec'],
            'bank_account_id' => $this->acc['bank'],
        ])->assertCreated()->json('data');

        // 12 monthly lines; PV of 12×1000 at 6%/yr is below 12,000.
        $this->assertCount(12, $lease['lines']);
        $pv = (float) $lease['initial_liability'];
        $this->assertGreaterThan(11000, $pv);
        $this->assertLessThan(12000, $pv);

        // Initial recognition: Dr ROU = Cr liability = PV.
        $init = GlTransaction::query()->where('source_type', Lease::class)->get();
        $this->assertEqualsWithDelta($pv, $init->firstWhere('account_id', $this->acc['rou'])->base_debit, 0.01);
        $this->assertEqualsWithDelta($pv, $init->firstWhere('account_id', $this->acc['liab'])->base_credit, 0.01);

        // Run the first 2 months.
        $run = $this->postJson('/api/v1/general-ledger/leases/run', ['as_of' => '2026-02-28'])->assertOk()->json('data');
        $this->assertSame(2, $run['recognized']);
        $this->assertEqualsWithDelta(2000, (float) $run['payments'], 0.01);

        // Bank credited 2×1000 over the two monthly payments.
        $bankCr = GlTransaction::query()->where('account_id', $this->acc['bank'])->sum('base_credit');
        $this->assertEqualsWithDelta(2000, $bankCr, 0.01);
    }

    public function test_ecl_matrix_applies_loss_rates_and_posts_the_allowance_movement(): void
    {
        $this->actAs('finance-manager');
        $u = User::factory()->create();
        $customer = \App\Models\Customer::query()->create([
            'customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active',
            'currency_code' => 'SAR', 'default_receivable_account_id' => $this->acc['bank'], 'payment_terms_days' => 30, 'credit_limit' => 0, 'created_by' => $u->id,
        ]);

        // A posted invoice 100 days overdue → bucket 91_120 (35% default), exposure 10,000.
        CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $customer->id, 'invoice_date' => now()->subDays(100)->toDateString(), 'due_date' => now()->subDays(100)->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 10000, 'tax_amount' => 0, 'total_amount' => 10000, 'amount_paid' => 0,
            'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);

        $matrix = $this->getJson('/api/v1/general-ledger/ecl/matrix?as_of='.now()->toDateString())->assertOk()->json('data');
        $bucket = collect($matrix['rows'])->firstWhere('bucket', '91_120');
        $this->assertEqualsWithDelta(10000, $bucket['exposure'], 0.01);
        $this->assertEqualsWithDelta(3500, $bucket['ecl'], 0.01);     // 10000 × 35%
        $this->assertEqualsWithDelta(3500, (float) $matrix['total_ecl'], 0.01);

        // Post the provision: Dr impairment 3500 / Cr allowance 3500.
        $this->postJson('/api/v1/general-ledger/ecl/post', [
            'as_of' => now()->toDateString(), 'expense_account_id' => $this->acc['ecl_exp'], 'allowance_account_id' => $this->acc['allowance'],
        ])->assertCreated();

        $this->assertEqualsWithDelta(3500, GlTransaction::query()->where('account_id', $this->acc['ecl_exp'])->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(3500, GlTransaction::query()->where('account_id', $this->acc['allowance'])->sum('base_credit'), 0.01);
    }
}
