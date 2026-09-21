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
use App\Services\VatReturnService;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F14 — Reverse-charge VAT (RCM) on imports. The foreign vendor charges no VAT;
 * the buyer self-assesses output VAT and reclaims it as input VAT (net-zero),
 * pays the vendor the net, and the self-assessed VAT shows in the ZATCA return.
 */
final class VendorInvoiceReverseChargeTest extends TestCase
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

        $expense = ChartOfAccount::query()->create(['code' => '5200', 'name' => 'Imported services', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);

        $inputHeader = ChartOfAccount::query()->create(['code' => '1207', 'name' => 'VAT Receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        ChartOfAccount::query()->create(['code' => '120701001', 'name' => 'VAT Receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 2, 'parent_id' => $inputHeader->id, 'is_postable' => true, 'status' => 'active']);

        $outputHeader = ChartOfAccount::query()->create(['code' => '223', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        ChartOfAccount::query()->create(['code' => '223001', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 2, 'parent_id' => $outputHeader->id, 'is_postable' => true, 'status' => 'active']);

        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);

        $vat = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'Saudi VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'input_account_id' => $inputHeader->id, 'output_account_id' => $outputHeader->id, 'is_recoverable' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $creator = User::factory()->create();
        $vendor = Vendor::query()->create([
            'vendor_code' => 'VEND-2026-000001', 'name' => 'Foreign Consulting Ltd', 'vendor_type' => 'supplier',
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

    private function payload(): array
    {
        return [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_no' => 'IMP-9001',
            'invoice_date' => '2026-03-15',
            'description' => 'Imported consultancy',
            'reverse_charge' => true,
            'lines' => [
                ['account_id' => $this->expenseId, 'amount' => 1000, 'tax_code_id' => $this->taxCodeId, 'description' => 'Consulting'],
            ],
        ];
    }

    public function test_reverse_charge_total_is_net_of_self_assessed_vat(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '1000.00')
            ->assertJsonPath('data.tax_amount', '150.00')   // self-assessed
            ->assertJsonPath('data.total_amount', '1000.00') // payable is net only
            ->assertJsonPath('data.reverse_charge', true);
    }

    public function test_posting_self_assesses_both_vat_legs_and_balances(): void
    {
        $this->actingAsRole('accountant');
        $invoice = $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())->json('data');

        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$invoice['id']}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_type', VendorInvoice::class)->where('approvable_id', $invoice['id'])->value('id');

        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$invoice['id']}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', VendorInvoice::class)->where('source_id', $invoice['id'])->get();

        // Expense Dr 1000 + Input VAT Dr 150 + Output VAT Cr 150 + AP Cr 1000.
        $this->assertCount(4, $rows);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_credit'), 0.01);

        $this->assertEqualsWithDelta(150, $rows->firstWhere('description', 'Input VAT (reverse charge)')->base_debit, 0.01);
        $this->assertEqualsWithDelta(150, $rows->firstWhere('description', 'Output VAT (reverse charge)')->base_credit, 0.01);
        // The vendor is owed only the net.
        $apRow = $rows->first(fn ($r): bool => str_starts_with((string) $r->description, 'AP — '));
        $this->assertEqualsWithDelta(1000, $apRow->base_credit, 0.01);
    }

    public function test_reverse_charge_appears_in_vat_return_memo_and_nets_to_zero(): void
    {
        $this->actingAsRole('accountant');
        $invoice = $this->postJson('/api/v1/accounts-payable/vendor-invoices', $this->payload())->json('data');
        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$invoice['id']}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_type', VendorInvoice::class)->where('approvable_id', $invoice['id'])->value('id');
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$invoice['id']}/post")->assertOk();

        $return = app(VatReturnService::class)->vatReturn(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertEqualsWithDelta(150, $return['reverse_charge_vat'], 0.01);
        $this->assertEqualsWithDelta(1000, $return['reverse_charge_base'], 0.01);
        // Output and input both carry the 150, so the RCM contributes zero net.
        $this->assertEqualsWithDelta(150, $return['output_vat_total'], 0.01);
        $this->assertEqualsWithDelta(150, $return['input_vat_total'], 0.01);
        $this->assertEqualsWithDelta(0, $return['net_vat_payable'], 0.01);
    }
}
