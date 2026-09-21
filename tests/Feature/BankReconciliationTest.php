<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankReconciliation;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P5 — Bank reconciliation: clear GL transactions against a statement balance.
 */
final class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;

    private int $periodId;

    private int $userId;

    /** @var array<int,int> */
    private array $txn = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class]);

        $u = User::factory()->create();
        $this->userId = $u->id;
        $this->bankId = ChartOfAccount::query()->create(['code' => '1101', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        // Three bank movements: +1,000, −300, +500 (book balance 1,200).
        $this->txn[1] = $this->glRow('2026-03-01', 1000, 0);
        $this->txn[2] = $this->glRow('2026-03-05', 0, 300);
        $this->txn[3] = $this->glRow('2026-03-10', 500, 0);
    }

    private function glRow(string $date, float $debit, float $credit): int
    {
        return (int) DB::table('gl_transactions')->insertGetId([
            'batch_number' => 'GLB-'.$date, 'fiscal_period_id' => $this->periodId, 'transaction_date' => $date,
            'account_id' => $this->bankId, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'debit' => $debit, 'credit' => $credit, 'base_debit' => $debit, 'base_credit' => $credit,
            'source_type' => 'App\\Models\\JournalEntry', 'source_id' => 1, 'posted_by' => $this->userId,
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_ledger_shows_running_balance_and_cleared_flags(): void
    {
        $this->actingAsRole('auditor');

        $data = $this->getJson("/api/v1/banking/accounts/{$this->bankId}/ledger")->assertOk()->json('data');

        $this->assertEqualsWithDelta(1200, (float) $data['book_balance'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $data['cleared_balance'], 0.01);
        $this->assertCount(3, $data['rows']);
        $this->assertEqualsWithDelta(700, (float) $data['rows'][1]['balance'], 0.01); // 1,000 − 300
        $this->assertFalse($data['rows'][0]['cleared']);
    }

    public function test_reconcile_clears_matching_items_to_a_zero_difference(): void
    {
        $this->actingAsRole('accountant');

        // Statement shows the first two items: 1,000 − 300 = 700.
        $this->postJson("/api/v1/banking/accounts/{$this->bankId}/reconcile", [
            'statement_date' => '2026-03-08',
            'statement_balance' => 700,
            'cleared_transaction_ids' => [$this->txn[1], $this->txn[2]],
        ])->assertCreated()->assertJsonPath('data.cleared_total', '700.00');

        $this->assertDatabaseCount('bank_reconciliation_lines', 2);

        // The third deposit remains uncleared.
        $ledger = $this->getJson("/api/v1/banking/accounts/{$this->bankId}/ledger")->json('data');
        $this->assertEqualsWithDelta(700, (float) $ledger['cleared_balance'], 0.01);
        $this->assertEqualsWithDelta(500, (float) $ledger['uncleared_total'], 0.01);
    }

    public function test_reconcile_out_of_balance_is_rejected(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson("/api/v1/banking/accounts/{$this->bankId}/reconcile", [
            'statement_date' => '2026-03-08',
            'statement_balance' => 999, // doesn't match the cleared 700
            'cleared_transaction_ids' => [$this->txn[1], $this->txn[2]],
        ])->assertStatus(422);

        $this->assertSame(0, BankReconciliation::query()->count());
    }

    public function test_accounts_lists_bank_accounts_with_book_balance(): void
    {
        $this->actingAsRole('auditor');

        $data = $this->getJson('/api/v1/banking/accounts')->assertOk()->json('data');
        $this->assertSame('1101', $data[0]['code']);
        $this->assertEqualsWithDelta(1200, (float) $data[0]['book_balance'], 0.01);
    }
}
