<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerNote;
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
 * AR credit & debit notes: credit reverses AR (Dr revenue+VAT / Cr AR);
 * debit adds AR (Dr AR / Cr revenue+VAT).
 */
final class CustomerNoteApiTest extends TestCase
{
    use RefreshDatabase;

    private int $arId;
    private int $revenueId;
    private int $vatOutputId;
    private int $vat15Id;
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->arId = ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->revenueId = ChartOfAccount::query()->create(['code' => '410101', 'name' => 'Sales', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vatOutputId = ChartOfAccount::query()->create(['code' => '220301', 'name' => 'Output VAT', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vat15Id = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'output_account_id' => $this->vatOutputId, 'is_recoverable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open']);

        $u = User::factory()->create();
        $this->customerId = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $this->arId, 'payment_terms_days' => 30, 'created_by' => $u->id])->id;
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    private function newNote(string $type): int
    {
        return $this->postJson('/api/v1/accounts-receivable/customer-notes', [
            'note_type' => $type, 'customer_id' => $this->customerId, 'note_date' => '2026-06-10',
            'lines' => [['account_id' => $this->revenueId, 'amount' => 1000, 'tax_code_id' => $this->vat15Id]],
        ])->assertCreated()->json('data.id');
    }

    public function test_credit_note_reverses_ar(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newNote('credit');
        CustomerNote::query()->whereKey($id)->update(['status' => 'approved']);

        $this->actingAsRole('finance-manager'); // accounts-receivable.post
        $this->postJson("/api/v1/accounts-receivable/customer-notes/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', CustomerNote::class)->where('source_id', $id)->get();
        // Dr revenue 1000 + Dr VAT 150 / Cr AR 1150.
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->revenueId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(150, (float) $rows->firstWhere('account_id', $this->vatOutputId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1150, (float) $rows->firstWhere('account_id', $this->arId)->base_credit, 0.01);
    }

    public function test_debit_note_adds_ar(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newNote('debit');
        CustomerNote::query()->whereKey($id)->update(['status' => 'approved']);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-receivable/customer-notes/{$id}/post")->assertOk();

        $rows = GlTransaction::query()->where('source_type', CustomerNote::class)->where('source_id', $id)->get();
        // Dr AR 1150 / Cr revenue 1000 + Cr VAT 150.
        $this->assertEqualsWithDelta(1150, (float) $rows->firstWhere('account_id', $this->arId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->revenueId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(150, (float) $rows->firstWhere('account_id', $this->vatOutputId)->base_credit, 0.01);
    }
}
