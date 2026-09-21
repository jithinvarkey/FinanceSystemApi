<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\RecurringInvoice;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recurring invoices: a template materialises a draft invoice and advances its
 * next run date.
 */
final class RecurringInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $revenueId;
    private int $vat15Id;
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $ar = ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->revenueId = ChartOfAccount::query()->create(['code' => '410101', 'name' => 'Sales', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $vatHeader = ChartOfAccount::query()->create(['code' => '2230', 'name' => 'Output VAT', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vat15Id = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'output_account_id' => $vatHeader, 'is_recoverable' => true, 'status' => 'active'])->id;

        $u = User::factory()->create();
        $this->customerId = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'created_by' => $u->id])->id;
    }

    private function actingAsManager(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'accountant')->firstOrFail()); // has general-ledger.manage
        Sanctum::actingAs($user->fresh());
    }

    public function test_template_generates_draft_and_advances_schedule(): void
    {
        $this->actingAsManager();

        $id = $this->postJson('/api/v1/recurring-invoices', [
            'type' => 'customer', 'party_id' => $this->customerId, 'name' => 'Monthly retainer',
            'frequency' => 'monthly', 'start_date' => '2026-01-01',
            'lines' => [['account_id' => $this->revenueId, 'amount' => 5000, 'tax_code_id' => $this->vat15Id]],
        ])->assertCreated()->assertJsonPath('data.next_run_date', '2026-01-01')->json('data.id');

        $this->postJson("/api/v1/recurring-invoices/{$id}/generate")
            ->assertOk()->assertJsonPath('data.document', fn ($d) => str_starts_with($d, 'SINV-'));

        // A draft invoice now exists, and the schedule advanced one month.
        $this->assertSame(1, CustomerInvoice::query()->where('customer_id', $this->customerId)->count());
        $template = RecurringInvoice::query()->find($id);
        $this->assertSame('2026-02-01', $template->next_run_date->toDateString());
        $this->assertSame(1, $template->generated_count);
    }

    public function test_generate_due_catches_up_multiple_periods(): void
    {
        $this->actingAsManager();

        $id = $this->postJson('/api/v1/recurring-invoices', [
            'type' => 'customer', 'party_id' => $this->customerId, 'name' => 'Retainer',
            'frequency' => 'monthly', 'start_date' => '2026-01-01',
            'lines' => [['account_id' => $this->revenueId, 'amount' => 1000]],
        ])->json('data.id');

        // Run due as of mid-March ⇒ Jan, Feb, Mar = 3 invoices.
        $result = $this->postJson('/api/v1/recurring-invoices/generate-due', ['as_of' => '2026-03-15'])->assertOk()->json('data');
        $this->assertCount(3, $result);
        $this->assertSame(3, CustomerInvoice::query()->count());
    }
}
