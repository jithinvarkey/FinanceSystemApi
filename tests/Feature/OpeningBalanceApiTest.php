<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P2.9 — Opening balances: a directly-posted cutover journal with an equity plug.
 */
final class OpeningBalanceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;
    private int $arId;
    private int $equityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->buildFixture();
    }

    private function buildFixture(): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $this->bankId = ChartOfAccount::query()->create(['code' => '110101', 'name' => 'Bank — current', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;
        $this->arId = ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR control', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->equityId = ChartOfAccount::query()->create(['code' => '21199', 'name' => 'Opening Balance Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open']);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    public function test_posts_opening_journal_and_plugs_equity(): void
    {
        $this->actingAsRole('finance-manager');

        $res = $this->postJson('/api/v1/general-ledger/opening-balances', [
            'cutover_date' => '2026-01-01',
            'equity_account_id' => $this->equityId,
            'lines' => [
                ['account_id' => $this->bankId, 'debit' => 50000, 'credit' => 0],
                ['account_id' => $this->arId, 'debit' => 12000, 'credit' => 0],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.is_opening', true)
            ->assertJsonPath('data.status', 'posted');

        $journalId = $res->json('data.id');

        // 2 entered lines + 1 equity plug = 3, balanced at 62,000.
        $rows = GlTransaction::query()->where('source_type', JournalEntry::class)->where('source_id', $journalId)->get();
        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(62000, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(62000, $rows->sum('base_credit'), 0.01);
        // Equity carries the 62,000 credit plug.
        $equityRow = $rows->firstWhere('account_id', $this->equityId);
        $this->assertEqualsWithDelta(62000, (float) $equityRow->base_credit, 0.01);
        $this->assertStringStartsWith('OB-', JournalEntry::query()->find($journalId)->journal_number);
    }

    public function test_already_balanced_needs_no_equity(): void
    {
        $this->actingAsRole('finance-manager');

        $this->postJson('/api/v1/general-ledger/opening-balances', [
            'cutover_date' => '2026-01-01',
            'lines' => [
                ['account_id' => $this->bankId, 'debit' => 5000, 'credit' => 0],
                ['account_id' => $this->equityId, 'debit' => 0, 'credit' => 5000],
            ],
        ])->assertCreated()->assertJsonPath('data.total_debit', '5000.00');
    }

    public function test_unbalanced_without_equity_is_rejected(): void
    {
        $this->actingAsRole('finance-manager');

        $this->postJson('/api/v1/general-ledger/opening-balances', [
            'cutover_date' => '2026-01-01',
            'lines' => [
                ['account_id' => $this->bankId, 'debit' => 5000, 'credit' => 0],
            ],
        ])->assertStatus(422);
    }

    public function test_requires_post_permission(): void
    {
        $this->actingAsRole('accountant'); // has general-ledger.manage, not .post

        $this->postJson('/api/v1/general-ledger/opening-balances', [
            'cutover_date' => '2026-01-01',
            'equity_account_id' => $this->equityId,
            'lines' => [['account_id' => $this->bankId, 'debit' => 5000, 'credit' => 0]],
        ])->assertStatus(403);
    }
}
