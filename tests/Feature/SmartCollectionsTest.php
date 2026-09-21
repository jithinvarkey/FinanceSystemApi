<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Role;
use App\Models\User;
use App\Notifications\DunningReminderNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E4 — Smart collections: e-mail dunning reminders + log.
 */
final class SmartCollectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_reminder_and_logs_it(): void
    {
        Notification::fake();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $ar = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'email' => 'ar@gulf.example', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'created_by' => $u->id]);

        // Overdue ~45 days, balance 1,000.
        $invoice = CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $customer->id, 'invoice_date' => now()->subDays(75)->toDateString(), 'due_date' => now()->subDays(45)->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 1000, 'tax_amount' => 0, 'total_amount' => 1000, 'amount_paid' => 0,
            'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);

        $clerk = User::factory()->create();
        $clerk->roles()->attach(Role::query()->where('name', 'accountant')->firstOrFail());
        Sanctum::actingAs($clerk->fresh());

        $this->postJson('/api/v1/accounts-receivable/dunning/send', ['invoice_ids' => [$invoice->id]])
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        Notification::assertSentOnDemand(DunningReminderNotification::class);
        $this->assertDatabaseHas('dunning_logs', ['customer_invoice_id' => $invoice->id, 'level' => 2, 'sent_to' => 'ar@gulf.example']);
    }

    public function test_skips_invoices_that_are_not_overdue(): void
    {
        Notification::fake();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $ar = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $u = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'email' => 'a@b.com', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $invoice = CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-2', 'customer_id' => $customer->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(20)->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500, 'amount_paid' => 0,
            'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);

        $clerk = User::factory()->create();
        $clerk->roles()->attach(Role::query()->where('name', 'accountant')->firstOrFail());
        Sanctum::actingAs($clerk->fresh());

        $this->postJson('/api/v1/accounts-receivable/dunning/send', ['invoice_ids' => [$invoice->id]])
            ->assertOk()->assertJsonPath('data.sent', 0)->assertJsonPath('data.skipped', 1);
    }
}
