<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerAdvance;
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
 * On-account receipts: Dr bank / Cr advances; apply reduces advance + invoice.
 */
final class CustomerAdvanceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;
    private int $advId;
    private int $arId;
    private int $customerId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->bankId = ChartOfAccount::query()->create(['code' => '110101', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;
        $this->advId = ChartOfAccount::query()->create(['code' => '221001', 'name' => 'Customer advances', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->arId = ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Now', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']);

        $u = User::factory()->create();
        $this->customerId = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $this->arId, 'payment_terms_days' => 30, 'created_by' => $u->id])->id;
        $this->invoiceId = CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $this->customerId, 'invoice_date' => now()->toDateString(), 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 600, 'tax_amount' => 0, 'total_amount' => 600, 'amount_paid' => 0, 'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ])->id;
    }

    private function manager(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'finance-manager')->firstOrFail()); // manage+post on AR? manager has approve/post not manage
        Sanctum::actingAs($user->fresh());
    }

    public function test_advance_posts_and_applies_to_invoice(): void
    {
        // accountant has accounts-receivable.manage to create.
        $acct = User::factory()->create();
        $acct->roles()->attach(Role::query()->where('name', 'accountant')->firstOrFail());
        Sanctum::actingAs($acct->fresh());

        $id = $this->postJson('/api/v1/accounts-receivable/customer-advances', [
            'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
            'bank_account_id' => $this->bankId, 'advance_account_id' => $this->advId, 'amount' => 1000,
        ])->assertCreated()->json('data.id');

        // finance-manager posts + applies (has accounts-receivable.post).
        $this->manager();
        $this->postJson("/api/v1/accounts-receivable/customer-advances/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        // GL: Dr bank 1000 / Cr advances 1000.
        $rows = GlTransaction::query()->where('source_type', CustomerAdvance::class)->where('source_id', $id)->get();
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->bankId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->advId)->base_credit, 0.01);

        // Apply 600 to the invoice.
        $this->postJson("/api/v1/accounts-receivable/customer-advances/{$id}/apply", ['customer_invoice_id' => $this->invoiceId, 'amount' => 600])
            ->assertOk()->assertJsonPath('data.unapplied', '400.00');

        $this->assertEqualsWithDelta(0, CustomerInvoice::query()->find($this->invoiceId)->balanceDue(), 0.01);
    }
}
