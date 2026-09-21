<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\PettyCashVoucher;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P6 — Petty cash voucher: Dr expense + input VAT / Cr the petty-cash float.
 */
final class PettyCashApiTest extends TestCase
{
    use RefreshDatabase;

    private int $cashId;
    private int $expenseId;
    private int $vatInputId;
    private int $vat15Id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->cashId = ChartOfAccount::query()->create(['code' => '110201', 'name' => 'Petty cash', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expenseId = ChartOfAccount::query()->create(['code' => '510102', 'name' => 'Sundry', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
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

    private function newVoucher(): int
    {
        return $this->postJson('/api/v1/petty-cash/vouchers', [
            'voucher_date' => '2026-06-12',
            'petty_cash_account_id' => $this->cashId,
            'expense_account_id' => $this->expenseId,
            'payee' => 'Taxi',
            'amount' => 200,
            'tax_code_id' => $this->vat15Id,
        ])->assertCreated()->json('data.id');
    }

    public function test_create_computes_total(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/petty-cash/vouchers', [
            'voucher_date' => '2026-06-12', 'petty_cash_account_id' => $this->cashId,
            'expense_account_id' => $this->expenseId, 'payee' => 'Taxi', 'amount' => 200, 'tax_code_id' => $this->vat15Id,
        ])->assertCreated()
            ->assertJsonPath('data.amount', '200.00')
            ->assertJsonPath('data.tax_amount', '30.00')
            ->assertJsonPath('data.total_amount', '230.00');
    }

    public function test_post_writes_balanced_gl(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newVoucher();

        $this->actingAsRole('finance-manager'); // accounts-payable.post
        $this->postJson("/api/v1/petty-cash/vouchers/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', PettyCashVoucher::class)->where('source_id', $id)->get();
        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(200, (float) $rows->firstWhere('account_id', $this->expenseId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(30, (float) $rows->firstWhere('account_id', $this->vatInputId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(230, (float) $rows->firstWhere('account_id', $this->cashId)->base_credit, 0.01);
    }

    public function test_post_requires_post_permission(): void
    {
        $this->actingAsRole('accountant'); // manage but not post
        $id = $this->newVoucher();

        $this->postJson("/api/v1/petty-cash/vouchers/{$id}/post")->assertStatus(403);
    }
}
