<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P9 — ZATCA VAT return: output VAT − recoverable input VAT = net payable.
 */
final class VatReturnTest extends TestCase
{
    use RefreshDatabase;

    private int $outputId;

    private int $childOutputId;

    private int $inputId;

    private int $periodId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class]);

        $u = User::factory()->create();
        $this->userId = $u->id;

        $output = ChartOfAccount::query()->create(['code' => '223', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        $childOutput = ChartOfAccount::query()->create(['code' => '223001', 'name' => 'VAT Payable - leaf', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 2, 'parent_id' => $output->id, 'is_postable' => true, 'status' => 'active']);
        $input = ChartOfAccount::query()->create(['code' => '1207', 'name' => 'VAT Receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $this->outputId = $output->id;
        $this->childOutputId = $childOutput->id;
        $this->inputId = $input->id;

        TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'input_account_id' => $input->id, 'output_account_id' => $output->id, 'is_recoverable' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        // Output VAT collected on a sale (Cr 150 to a leaf under the 223 header),
        // input VAT paid on a purchase (Dr 30 on 1207), and one out-of-period row.
        $this->glRow('2026-03-10', $this->childOutputId, 0, 150);
        $this->glRow('2026-03-12', $this->inputId, 30, 0);
        $this->glRow('2026-02-01', $this->childOutputId, 0, 999); // before the period
    }

    private function glRow(string $date, int $accountId, float $debit, float $credit): void
    {
        DB::table('gl_transactions')->insert([
            'batch_number' => 'GLB-'.$date, 'fiscal_period_id' => $this->periodId, 'transaction_date' => $date,
            'account_id' => $accountId, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'debit' => $debit, 'credit' => $credit, 'base_debit' => $debit, 'base_credit' => $credit,
            'source_type' => 'App\\Models\\JournalEntry', 'source_id' => 1, 'posted_by' => $this->userId,
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actingAsViewer(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'auditor')->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_vat_return_nets_output_less_input_for_the_period(): void
    {
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/tax/vat-return?from=2026-03-01&to=2026-03-31')->assertOk()->json('data');

        // Output VAT picks up the child leaf under the 223 header; the Feb row is excluded.
        $this->assertEqualsWithDelta(150, (float) $data['output_vat_total'], 0.01);
        $this->assertEqualsWithDelta(30, (float) $data['input_vat_total'], 0.01);
        $this->assertEqualsWithDelta(120, (float) $data['net_vat_payable'], 0.01);
        $this->assertSame('223001', $data['output_vat'][0]['code']);
    }
}
