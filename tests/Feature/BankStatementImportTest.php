<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
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
 * F20 — Bank statement import + auto-matching to GL transactions.
 */
final class BankStatementImportTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccount $bank;
    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->bank = ChartOfAccount::query()->create([
            'code' => '1010', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active',
        ]);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;
    }

    private function glRow(string $date, float $debit, float $credit): GlTransaction
    {
        return GlTransaction::query()->create([
            'batch_number' => 'GLB-'.str_replace('-', '', $date).'-000001',
            'fiscal_period_id' => $this->periodId, 'transaction_date' => $date, 'account_id' => $this->bank->id,
            'debit' => $debit, 'credit' => $credit, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'base_debit' => $debit, 'base_credit' => $credit, 'source_type' => 'test', 'source_id' => 1,
            'description' => 'gl', 'is_reversal' => false, 'posted_by' => User::factory()->create()->id, 'posted_at' => now(),
        ]);
    }

    private function actAsManager(): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', 'finance-manager')->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_import_parses_signed_amounts_from_debit_credit_columns(): void
    {
        $this->actAsManager();

        $csv = "Date,Description,Debit,Credit,Balance\n"
            ."2026-03-02,Customer deposit,,500.00,1500.00\n"
            ."2026-03-03,Supplier payment,200.00,,1300.00\n";

        $this->postJson("/api/v1/banking/accounts/{$this->bank->id}/statement/import", ['csv' => $csv])
            ->assertCreated()
            ->assertJsonPath('data.imported', 2);

        $this->assertDatabaseHas('bank_statement_lines', ['bank_account_id' => $this->bank->id, 'amount' => 500.00]);
        $this->assertDatabaseHas('bank_statement_lines', ['bank_account_id' => $this->bank->id, 'amount' => -200.00]);
    }

    public function test_auto_match_links_statement_lines_to_equal_gl_movements(): void
    {
        $this->actAsManager();

        // GL: a +500 deposit and a −200 payment on the bank account.
        $deposit = $this->glRow('2026-03-02', 500, 0);
        $payment = $this->glRow('2026-03-03', 0, 200);

        $csv = "Date,Description,Amount\n2026-03-02,Deposit,500.00\n2026-03-03,Payment,-200.00\n";
        $this->postJson("/api/v1/banking/accounts/{$this->bank->id}/statement/import", ['csv' => $csv])->assertCreated();

        $this->postJson("/api/v1/banking/accounts/{$this->bank->id}/statement/auto-match")
            ->assertOk()
            ->assertJsonPath('data.matched', 2);

        $matched = $this->getJson("/api/v1/banking/accounts/{$this->bank->id}/statement")->json('matched_gl_ids');
        $this->assertContains($deposit->id, $matched);
        $this->assertContains($payment->id, $matched);
    }

    public function test_statement_suggests_a_single_candidate(): void
    {
        $this->actAsManager();
        $gl = $this->glRow('2026-03-05', 0, 750);

        $csv = "Date,Description,Amount\n2026-03-05,Rent,-750.00\n";
        $this->postJson("/api/v1/banking/accounts/{$this->bank->id}/statement/import", ['csv' => $csv])->assertCreated();

        $lines = $this->getJson("/api/v1/banking/accounts/{$this->bank->id}/statement")->json('data');
        $this->assertSame($gl->id, $lines[0]['suggested_gl_transaction_id']);
    }
}
