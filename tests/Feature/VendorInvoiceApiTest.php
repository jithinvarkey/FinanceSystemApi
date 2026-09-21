<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P3.6–P3.10 — Vendor invoice: VAT 15%, duplicate guard, and the
 * approve → post flow that writes a balanced GL entry.
 */
final class VendorInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $expenseId;
    private int $taxCodeId;
    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->buildFixture();
    }

    private function buildFixture(): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $expense = ChartOfAccount::query()->create(['code' => '5200', 'name' => 'Office & admin', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $vatHeader = ChartOfAccount::query()->create(['code' => '1207', 'name' => 'VAT Receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        ChartOfAccount::query()->create(['code' => '120701001', 'name' => 'VAT Receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 2, 'parent_id' => $vatHeader->id, 'is_postable' => true, 'status' => 'active']);
        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);

        $vat = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'Saudi VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'input_account_id' => $vatHeader->id, 'is_recoverable' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $creator = User::factory()->create();
        $vendor = Vendor::query()->create([
            'vendor_code' => 'VEND-2026-000001', 'name' => 'Acme Trading', 'vendor_type' => 'supplier',
            'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id,
            'payment_terms_days' => 30, 'created_by' => $creator->id,
        ]);

        $this->expenseId = $expense->id;
        $this->taxCodeId = $vat->id;
        $this->vendorId = $vendor->id;
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
            'vendor_id' => $this->vendorId,
            'vendor_invoice_no' => 'SUP-1001',
            'invoice_date' => '2026-03-15',
            'description' => 'March services',
            'lines' => [
                ['account_id' => $this->expenseId, 'amount' => 1000, 'tax_code_id' => $this->taxCodeId, 'description' => 'Consulting'],
            ],
        ], $overrides);
    }

    public function test_create_computes_vat_15_percent(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '1000.00')
            ->assertJsonPath('data.tax_amount', '150.00')
            ->assertJsonPath('data.total_amount', '1150.00')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_duplicate_vendor_invoice_number_is_blocked(): void
    {
        $this->actingAsRole('accountant');
        $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())->assertCreated();

        $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())
            ->assertStatus(409);
    }

    public function test_approve_then_post_writes_a_balanced_gl_entry(): void
    {
        $maker = $this->actingAsRole('accountant');
        $invoice = $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())->json('data');

        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$invoice['id']}/submit")
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $requestId = ApprovalRequest::query()->where('approvable_type', VendorInvoice::class)->where('approvable_id', $invoice['id'])->value('id');

        // Controller clears the single step (amount < 25,000).
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();
        $this->assertSame('approved', VendorInvoice::query()->find($invoice['id'])->status->value);

        // Finance manager posts it to the GL.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$invoice['id']}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', VendorInvoice::class)->where('source_id', $invoice['id'])->get();
        $this->assertCount(3, $rows); // expense + input VAT + payable
        $this->assertEqualsWithDelta(1150, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_credit'), 0.01);
        // VAT leg = 150 debit to the postable VAT leaf.
        $this->assertEqualsWithDelta(150, $rows->firstWhere('description', 'Input VAT')->base_debit, 0.01);
    }

    public function test_cannot_post_an_unapproved_invoice(): void
    {
        $this->actingAsRole('accountant');
        $invoice = $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())->json('data');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$invoice['id']}/post")
            ->assertStatus(409);
    }
}
