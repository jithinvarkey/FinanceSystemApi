<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DocumentNumberService;
use App\Services\VendorInvoiceService;
use Illuminate\Database\Seeder;

/**
 * Demo data for the AP walkthrough: an active vendor + one posted invoice.
 * Run with: php artisan db:seed --class=DemoApSeeder
 */
final class DemoApSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'test@example.com')->firstOrFail();
        $expense = ChartOfAccount::query()->where('account_type', 'expense')->where('is_postable', true)->orderBy('code')->firstOrFail();
        $payable = ChartOfAccount::query()->where('code', '2218')->firstOrFail();
        $vat = TaxCode::query()->where('code', 'VAT15')->firstOrFail();

        $vendor = Vendor::query()->firstOrCreate(
            ['name' => 'Gulf Office Supplies'],
            [
                'vendor_code' => app(DocumentNumberService::class)->next('vendor'),
                'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR',
                'default_payable_account_id' => $payable->id, 'payment_terms_days' => 30,
                'created_by' => $user->id, 'trn' => '310000000000003',
            ],
        );

        $service = app(VendorInvoiceService::class);
        $invoice = $service->createDraft([
            'vendor_id' => $vendor->id, 'vendor_invoice_no' => 'SUP-7001', 'invoice_date' => '2026-03-12',
            'description' => 'March supplies',
            'lines' => [['account_id' => $expense->id, 'amount' => 1000, 'tax_code_id' => $vat->id, 'description' => 'Stationery']],
        ], $user->id);

        $invoice->forceFill(['status' => 'approved'])->save();
        $posted = $service->post($invoice->fresh(), $user->id);

        $this->command?->info("Vendor {$vendor->vendor_code}, invoice {$posted->invoice_number} posted (batch {$posted->batch_number}) using expense {$expense->code}.");
    }
}
