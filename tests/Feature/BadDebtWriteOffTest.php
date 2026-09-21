<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bad-debt write-off: Dr bad-debt expense / Cr AR for the balance, closing the invoice.
 */
final class BadDebtWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private int $arId;
    private int $badDebtId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->arId = ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->badDebtId = ChartOfAccount::query()->create(['code' => '530101', 'name' => 'Bad debt expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $period = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $period2 = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 7, 'name' => 'Now', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']);

        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $this->arId, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $this->invoiceId = CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-06-01', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 1000, 'tax_amount' => 0, 'total_amount' => 1000, 'amount_paid' => 0,
            'status' => 'posted', 'fiscal_period_id' => $period->id, 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ])->id;
    }

    public function test_write_off_posts_bad_debt_and_closes_invoice(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'finance-manager')->firstOrFail());
        Sanctum::actingAs($user->fresh());

        $this->postJson("/api/v1/accounts-receivable/customer-invoices/{$this->invoiceId}/write-off", ['bad_debt_account_id' => $this->badDebtId])
            ->assertOk();

        $rows = GlTransaction::query()->where('source_type', CustomerInvoice::class)->where('source_id', $this->invoiceId)->get();
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->badDebtId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->arId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(0, CustomerInvoice::query()->find($this->invoiceId)->balanceDue(), 0.01);
    }
}
