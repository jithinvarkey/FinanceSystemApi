<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E1 — Executive Command Center widgets.
 */
final class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_center_aggregates_widgets(): void
    {
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $ar = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $exp = ChartOfAccount::query()->create(['code' => '5200', 'name' => 'Opex', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY', 'name' => 'FY', 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(), 'status' => 'open']);
        $period = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => (int) now()->month, 'name' => 'M', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']);

        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf Logistics', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $customer->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 1000, 'tax_amount' => 0, 'total_amount' => 1000, 'amount_paid' => 0,
            'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);

        // Budget 12,000 vs actual expense 3,000.
        $budget = Budget::query()->create(['name' => 'Annual', 'fiscal_year_id' => $year->id, 'status' => 'active', 'created_by' => $u->id]);
        BudgetLine::query()->create(['budget_id' => $budget->id, 'account_id' => $exp, 'annual_amount' => 12000]);
        GlTransaction::query()->create(['batch_number' => 'GLB-1', 'fiscal_period_id' => $period->id, 'transaction_date' => now()->toDateString(), 'account_id' => $exp, 'debit' => 3000, 'credit' => 0, 'currency_code' => 'SAR', 'exchange_rate' => 1, 'base_debit' => 3000, 'base_credit' => 0, 'source_type' => 't', 'source_id' => 1, 'description' => 'x', 'is_reversal' => false, 'posted_by' => $u->id, 'posted_at' => now()]);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('name', 'finance-manager')->firstOrFail());
        Sanctum::actingAs($admin->fresh());

        $data = $this->getJson('/api/v1/dashboard/command-center')->assertOk()->json('data');

        $this->assertCount(3, $data['cash_forecast']);
        $this->assertSame('Gulf Logistics', $data['top_customers'][0]['name']);
        $this->assertEqualsWithDelta(1000, $data['top_customers'][0]['outstanding'], 0.01);
        $this->assertEqualsWithDelta(1000, $data['cash_forecast'][0]['expected_in'], 0.01);
        $this->assertEqualsWithDelta(12000, $data['budget_vs_actual']['budget'], 0.01);
        $this->assertEqualsWithDelta(3000, $data['budget_vs_actual']['actual'], 0.01);
        $this->assertArrayHasKey('collection_risk_index', $data);
        $this->assertEqualsWithDelta(1000, $data['aging']['total'], 0.01);
    }
}
