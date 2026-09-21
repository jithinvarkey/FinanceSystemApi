<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\VatReturnStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\VatReturn;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use App\Services\VatFilingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F15 — VAT filing workflow: snapshot a draft, file it (locking the period), and
 * settle the net with ZATCA clearing the VAT control accounts.
 */
final class VatFilingTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;
    private int $inputLeafId;
    private int $outputLeafId;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $bank = ChartOfAccount::query()->create(['code' => '1010', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);

        $inputHeader = ChartOfAccount::query()->create(['code' => '1207', 'name' => 'VAT Receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        $inputLeaf = ChartOfAccount::query()->create(['code' => '120701001', 'name' => 'VAT Receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 2, 'parent_id' => $inputHeader->id, 'is_postable' => true, 'status' => 'active']);

        $outputHeader = ChartOfAccount::query()->create(['code' => '223', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        $outputLeaf = ChartOfAccount::query()->create(['code' => '223001', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 2, 'parent_id' => $outputHeader->id, 'is_postable' => true, 'status' => 'active']);

        TaxCode::query()->create(['code' => 'VAT15', 'name' => 'Saudi VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'input_account_id' => $inputHeader->id, 'output_account_id' => $outputHeader->id, 'is_recoverable' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $this->bankId = $bank->id;
        $this->inputLeafId = $inputLeaf->id;
        $this->outputLeafId = $outputLeaf->id;

        // Seed VAT movement in the period: output VAT 300 (Cr), input VAT 100 (Dr) → net 200.
        $user = User::factory()->create();
        app(GlPostingService::class)->post(
            $bank,
            Carbon::parse('2026-03-15'),
            [
                new PostingLine($this->bankId, 200, 0.0, 'SAR'),
                new PostingLine($this->inputLeafId, 100, 0.0, 'SAR'),
                new PostingLine($this->outputLeafId, 0.0, 300, 'SAR'),
            ],
            $user->id,
        );
    }

    private function service(): VatFilingService
    {
        return app(VatFilingService::class);
    }

    public function test_draft_snapshots_the_computed_return(): void
    {
        $user = User::factory()->create();
        $draft = $this->service()->createDraft(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), $user->id);

        $this->assertSame(VatReturnStatus::Draft, $draft->status);
        $this->assertEqualsWithDelta(300, (float) $draft->output_vat, 0.01);
        $this->assertEqualsWithDelta(100, (float) $draft->input_vat, 0.01);
        $this->assertEqualsWithDelta(200, (float) $draft->net_vat_payable, 0.01);
        $this->assertStringStartsWith('VATR-', $draft->reference);
    }

    public function test_filing_locks_the_period_against_new_postings(): void
    {
        $user = User::factory()->create();
        $draft = $this->service()->createDraft(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), $user->id);
        $filed = $this->service()->file($draft, $user->id, 'ZATCA-Q1-REF');

        $this->assertSame(VatReturnStatus::Filed, $filed->status);
        $this->assertSame('ZATCA-Q1-REF', $filed->zatca_reference);

        // A non-VAT posting dated inside the filed period is now rejected.
        $bank = ChartOfAccount::query()->find($this->bankId);
        $this->expectException(FinanceRuleException::class);
        app(GlPostingService::class)->post(
            $bank,
            Carbon::parse('2026-03-20'),
            [new PostingLine($this->bankId, 50, 0.0, 'SAR'), new PostingLine($this->outputLeafId, 0.0, 50, 'SAR')],
            $user->id,
        );
    }

    public function test_payment_clears_vat_accounts_and_moves_net_cash(): void
    {
        $user = User::factory()->create();
        $draft = $this->service()->createDraft(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), $user->id);
        $filed = $this->service()->file($draft, $user->id);

        // Settlement is exempt from the VAT period lock even dated inside it.
        $paid = $this->service()->recordPayment($filed, $this->bankId, $user->id, Carbon::parse('2026-03-31'));

        $this->assertSame(VatReturnStatus::Paid, $paid->status);
        $this->assertNotNull($paid->payment_batch_number);

        $rows = GlTransaction::query()->where('batch_number', $paid->payment_batch_number)->get();
        // Dr output VAT 300 + Cr input VAT 100 + Cr bank 200.
        $this->assertEqualsWithDelta(300, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(300, $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(300, $rows->firstWhere('account_id', $this->outputLeafId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(100, $rows->firstWhere('account_id', $this->inputLeafId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(200, $rows->firstWhere('account_id', $this->bankId)->base_credit, 0.01);
    }

    public function test_nil_return_settles_without_a_gl_entry(): void
    {
        $user = User::factory()->create();
        // April has no VAT movement → a nil return.
        FiscalPeriod::query()->create(['fiscal_year_id' => FiscalYear::query()->value('id'), 'period_number' => 4, 'name' => 'Apr 2026', 'start_date' => '2026-04-01', 'end_date' => '2026-04-30', 'status' => 'open']);

        $draft = $this->service()->createDraft(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), $user->id);
        $this->assertEqualsWithDelta(0, (float) $draft->net_vat_payable, 0.01);

        $filed = $this->service()->file($draft, $user->id);
        $before = GlTransaction::query()->count();
        $paid = $this->service()->recordPayment($filed, $this->bankId, $user->id);

        $this->assertSame(VatReturnStatus::Paid, $paid->status);
        $this->assertNull($paid->payment_batch_number);
        $this->assertSame($before, GlTransaction::query()->count()); // no GL entry written
    }

    public function test_overlapping_filed_period_is_blocked(): void
    {
        $user = User::factory()->create();
        $draft = $this->service()->createDraft(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), $user->id);
        $this->service()->file($draft, $user->id);

        $this->expectException(FinanceRuleException::class);
        $this->service()->createDraft(Carbon::parse('2026-03-15'), Carbon::parse('2026-04-15'), $user->id);
    }
}
