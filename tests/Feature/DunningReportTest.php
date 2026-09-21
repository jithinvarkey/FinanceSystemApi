<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dunning report: overdue invoices with dunning level. Diamond does not levy
 * late-payment finance charges, so the report is a pure collections worklist.
 */
final class DunningReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $ar = ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'created_by' => $u->id]);

        // Overdue by ~45 days (due 2026-05-01, balance 1,000), as of 2026-06-15.
        CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-04-01', 'due_date' => '2026-05-01', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 1000, 'tax_amount' => 0, 'total_amount' => 1000, 'amount_paid' => 0, 'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);
    }

    public function test_dunning_lists_overdue_with_level(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'auditor')->firstOrFail());
        Sanctum::actingAs($user->fresh());

        $data = $this->getJson('/api/v1/accounts-receivable/reports/dunning?as_of=2026-06-15')->assertOk()->json('data');

        $this->assertCount(1, $data['rows']);
        $this->assertSame(2, $data['rows'][0]['level']); // 45 days → level 2
        $this->assertEqualsWithDelta(1000, (float) $data['totals']['overdue'], 0.01);
        // No finance charges are levied, so the row carries no charge field.
        $this->assertArrayNotHasKey('finance_charge', $data['rows'][0]);
        $this->assertArrayNotHasKey('charge', $data['totals']);
    }
}
