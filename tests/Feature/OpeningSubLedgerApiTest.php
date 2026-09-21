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
use App\Models\Vendor;
use App\Models\VendorInvoice;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P2.9b — opening sub-ledger items: open AR/AP invoices brought in at cutover.
 * Contra is Opening Balance Equity; the GL posts at the cutover (open) date
 * while the invoice keeps its original (historical) date for aging.
 */
final class OpeningSubLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    private int $arId;
    private int $apId;
    private int $obeId;
    private int $customerId;
    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->buildFixture();
    }

    private function buildFixture(): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $this->arId = ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR control', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->apId = ChartOfAccount::query()->create(['code' => '220101', 'name' => 'AP control', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->obeId = ChartOfAccount::query()->create(['code' => '21199', 'name' => 'Opening Balance Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open']);

        $u = User::factory()->create();
        $this->customerId = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $this->arId, 'payment_terms_days' => 30, 'created_by' => $u->id])->id;
        $this->vendorId = Vendor::query()->create(['vendor_code' => 'VEND-1', 'name' => 'Acme', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $this->apId, 'created_by' => $u->id])->id;
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_opening_receivable_posts_against_equity_and_keeps_original_number(): void
    {
        $this->actingAsRole('finance-manager');

        $res = $this->postJson('/api/v1/general-ledger/opening-balances/receivables', [
            'cutover_date' => '2026-01-01',
            'customer_id' => $this->customerId,
            'invoice_number' => 'LEGACY-AR-2015-77',
            'invoice_date' => '2015-09-01',
            'due_date' => '2015-10-01',
            'outstanding_amount' => 4600,
        ])->assertCreated()
            ->assertJsonPath('data.is_opening', true)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.invoice_number', 'LEGACY-AR-2015-77');

        $invoiceId = $res->json('data.id');
        $invoice = CustomerInvoice::query()->find($invoiceId);
        $this->assertEqualsWithDelta(4600, $invoice->balanceDue(), 0.01);
        $this->assertSame('2015-09-01', $invoice->invoice_date->toDateString());

        // GL: Dr AR 4600 / Cr OBE 4600, posted at the cutover date (open period).
        $rows = GlTransaction::query()->where('source_type', CustomerInvoice::class)->where('source_id', $invoiceId)->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(4600, (float) $rows->firstWhere('account_id', $this->arId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(4600, (float) $rows->firstWhere('account_id', $this->obeId)->base_credit, 0.01);
        $this->assertSame('2026-01-01', $rows->first()->transaction_date->toDateString());
    }

    public function test_opening_payable_posts_against_equity(): void
    {
        $this->actingAsRole('finance-manager');

        $res = $this->postJson('/api/v1/general-ledger/opening-balances/payables', [
            'cutover_date' => '2026-01-01',
            'vendor_id' => $this->vendorId,
            'invoice_number' => 'LEGACY-AP-9',
            'invoice_date' => '2015-11-01',
            'outstanding_amount' => 1500,
        ])->assertCreated()->assertJsonPath('data.is_opening', true);

        $invoiceId = $res->json('data.id');
        $rows = GlTransaction::query()->where('source_type', VendorInvoice::class)->where('source_id', $invoiceId)->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1500, (float) $rows->firstWhere('account_id', $this->obeId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1500, (float) $rows->firstWhere('account_id', $this->apId)->base_credit, 0.01);
    }

    public function test_duplicate_invoice_number_is_rejected(): void
    {
        $this->actingAsRole('finance-manager');
        $payload = [
            'cutover_date' => '2026-01-01', 'customer_id' => $this->customerId,
            'invoice_number' => 'DUP-1', 'invoice_date' => '2015-09-01', 'outstanding_amount' => 100,
        ];

        $this->postJson('/api/v1/general-ledger/opening-balances/receivables', $payload)->assertCreated();
        $this->postJson('/api/v1/general-ledger/opening-balances/receivables', $payload)->assertStatus(422);
    }

    public function test_requires_post_permission(): void
    {
        $this->actingAsRole('accountant'); // has general-ledger.manage, not .post

        $this->postJson('/api/v1/general-ledger/opening-balances/receivables', [
            'cutover_date' => '2026-01-01', 'customer_id' => $this->customerId,
            'invoice_number' => 'X-1', 'invoice_date' => '2015-09-01', 'outstanding_amount' => 100,
        ])->assertStatus(403);
    }
}
