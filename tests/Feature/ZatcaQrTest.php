<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F16 — ZATCA Fatoorah Phase-1 QR: the TLV/Base64 payload carries the five
 * mandatory tags (seller, VAT no, timestamp, total, VAT).
 */
final class ZatcaQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function postedInvoice(): CustomerInvoice
    {
        $ar = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'AR', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $user = User::factory()->create();
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $ar, 'payment_terms_days' => 30, 'created_by' => $user->id]);

        return CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-03-15',
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 100, 'tax_amount' => 15, 'total_amount' => 115,
            'amount_paid' => 0, 'status' => 'posted', 'created_by' => $user->id, 'posted_by' => $user->id, 'posted_at' => '2026-03-15 10:00:00',
        ]);
    }

    /** Decode a ZATCA TLV string into a [tag => value] map. */
    private function decodeTlv(string $binary): array
    {
        $out = [];
        $i = 0;
        $len = strlen($binary);
        while ($i + 2 <= $len) {
            $tag = ord($binary[$i]);
            $valLen = ord($binary[$i + 1]);
            $out[$tag] = substr($binary, $i + 2, $valLen);
            $i += 2 + $valLen;
        }

        return $out;
    }

    public function test_qr_endpoint_returns_tlv_with_the_five_mandatory_tags(): void
    {
        CompanySetting::current()->update(['company_name' => 'Diamond Insurance Broker', 'vat_number' => '300000000000003']);
        $invoice = $this->postedInvoice();

        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'auditor')->firstOrFail());
        Sanctum::actingAs($user->fresh());

        $data = $this->getJson("/api/v1/accounts-receivable/customer-invoices/{$invoice->id}/zatca-qr")
            ->assertOk()
            ->json('data');

        $this->assertSame('300000000000003', $data['vat_number']);
        $this->assertSame('115.00', $data['invoice_total']);
        $this->assertSame('15.00', $data['vat_total']);

        $tlv = $this->decodeTlv(base64_decode($data['tlv_base64']));
        $this->assertSame('Diamond Insurance Broker', $tlv[1]);
        $this->assertSame('300000000000003', $tlv[2]);
        $this->assertSame('2026-03-15T10:00:00Z', $tlv[3]);
        $this->assertSame('115.00', $tlv[4]);
        $this->assertSame('15.00', $tlv[5]);
    }

    public function test_qr_is_refused_for_a_draft_invoice(): void
    {
        $invoice = $this->postedInvoice();
        $invoice->update(['status' => 'draft']);

        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'auditor')->firstOrFail());
        Sanctum::actingAs($user->fresh());

        $this->getJson("/api/v1/accounts-receivable/customer-invoices/{$invoice->id}/zatca-qr")
            ->assertStatus(422);
    }

    public function test_company_settings_can_be_updated(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'it-supervisor')->firstOrFail());
        Sanctum::actingAs($user->fresh());

        $this->putJson('/api/v1/finance-config/company-settings', [
            'company_name' => 'Diamond Broker LLC',
            'vat_number' => '311111111111113',
        ])->assertOk()->assertJsonPath('data.vat_number', '311111111111113');

        $this->assertSame('Diamond Broker LLC', CompanySetting::current()->company_name);
    }
}
