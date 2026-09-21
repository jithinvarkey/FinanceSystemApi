<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\ExpenseClaim;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P6 — Expense claims: Dr expense (net) + Dr recoverable input VAT / Cr the
 * pay-from account (gross), through the single GL gateway.
 */
final class ExpenseClaimApiTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;
    private int $expenseId;
    private int $vatInputId;
    private int $vat15Id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->bankId = ChartOfAccount::query()->create(['code' => '110101', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;
        $this->expenseId = ChartOfAccount::query()->create(['code' => '510101', 'name' => 'Office expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vatInputId = ChartOfAccount::query()->create(['code' => '120701', 'name' => 'Input VAT', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vat15Id = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'input_account_id' => $this->vatInputId, 'is_recoverable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open']);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    private function newClaim(): int
    {
        return $this->postJson('/api/v1/expenses/claims', [
            'claimant' => 'Ahmed (office)',
            'expense_date' => '2026-06-10',
            'credit_account_id' => $this->bankId,
            'lines' => [
                ['account_id' => $this->expenseId, 'description' => 'Stationery', 'amount' => 1000, 'tax_code_id' => $this->vat15Id],
            ],
        ])->assertCreated()->json('data.id');
    }

    public function test_create_computes_vat_totals(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/expenses/claims', [
            'claimant' => 'Ahmed',
            'expense_date' => '2026-06-10',
            'credit_account_id' => $this->bankId,
            'lines' => [['account_id' => $this->expenseId, 'amount' => 1000, 'tax_code_id' => $this->vat15Id]],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', '1000.00')
            ->assertJsonPath('data.tax_amount', '150.00')
            ->assertJsonPath('data.total_amount', '1150.00');
    }

    public function test_post_writes_balanced_gl(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newClaim();

        // Approve out-of-band (approval engine is covered elsewhere), then post.
        ExpenseClaim::query()->whereKey($id)->update(['status' => 'approved']);
        $this->actingAsRole('finance-manager'); // has accounts-payable.post

        $this->postJson("/api/v1/expenses/claims/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', ExpenseClaim::class)->where('source_id', $id)->get();
        $this->assertCount(3, $rows); // expense + input VAT + bank
        $this->assertEqualsWithDelta(1150, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->expenseId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(150, (float) $rows->firstWhere('account_id', $this->vatInputId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1150, (float) $rows->firstWhere('account_id', $this->bankId)->base_credit, 0.01);
    }

    public function test_cannot_post_unapproved(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newClaim();

        $this->actingAsRole('finance-manager'); // can post, but the claim is still draft
        $this->postJson("/api/v1/expenses/claims/{$id}/post")->assertStatus(409);
    }

    public function test_create_requires_manage_permission(): void
    {
        $this->actingAsRole('auditor'); // view only

        $this->postJson('/api/v1/expenses/claims', [
            'claimant' => 'X', 'expense_date' => '2026-06-10', 'credit_account_id' => $this->bankId,
            'lines' => [['account_id' => $this->expenseId, 'amount' => 100]],
        ])->assertStatus(403);
    }
}
