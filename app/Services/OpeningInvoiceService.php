<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerInvoiceStatus;
use App\Enums\VendorInvoiceStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Database\Seeders\OpeningBalanceEquitySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P2.9b — opening sub-ledger items. Brings a still-open customer/vendor invoice
 * into the system at cutover as a POSTED open item, preserving its original
 * number and date so AR/AP aging and statements reconcile to the GL.
 *
 * The contra side is Opening Balance Equity, NOT revenue/expense — the income
 * was earned before cutover and must not be re-recognised. The GL leg posts at
 * the CUTOVER date (an open period); the invoice keeps its ORIGINAL date so it
 * ages correctly. Each item posts the OUTSTANDING balance, so the sum of the
 * AR/AP control postings equals the sub-ledger by construction.
 */
final class OpeningInvoiceService
{
    use ResolvesPostableAccount;

    public function __construct(private readonly GlPostingService $posting)
    {
    }

    /**
     * Open receivable at cutover: Dr AR control / Cr Opening Balance Equity.
     *
     * @param array<string, mixed> $data
     */
    public function postOpeningReceivable(array $data, int $userId): CustomerInvoice
    {
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $receivableId = $this->resolvePostable(
            $customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount(),
        );
        $equityId = $this->equityAccount($data['equity_account_id'] ?? null);
        $amount = round((float) $data['outstanding_amount'], 2);
        $cutover = Carbon::parse($data['cutover_date']);

        return DB::transaction(function () use ($data, $customer, $receivableId, $equityId, $amount, $cutover, $userId): CustomerInvoice {
            $invoice = CustomerInvoice::query()->create([
                'invoice_number' => $data['invoice_number'],
                'customer_id' => $customer->id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? $data['invoice_date'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? 'Opening balance',
                'currency_code' => 'SAR',
                'exchange_rate' => 1,
                'subtotal' => $amount,
                'tax_amount' => 0,
                'total_amount' => $amount,
                'amount_paid' => 0,
                'status' => CustomerInvoiceStatus::Posted,
                'is_opening' => true,
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            $invoice->lines()->create([
                'line_no' => 1,
                'account_id' => $equityId,
                'description' => 'Opening balance',
                'amount' => $amount,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'line_total' => $amount,
            ]);

            $rows = $this->posting->post($invoice, $cutover, [
                new PostingLine($receivableId, $amount, 0.0, 'SAR', 1.0, null, 'Opening AR — '.$customer->name),
                new PostingLine($equityId, 0.0, $amount, 'SAR', 1.0, null, 'Opening balance equity'),
            ], $userId);

            $invoice->update(['fiscal_period_id' => $rows->first()->fiscal_period_id, 'batch_number' => $rows->first()->batch_number]);

            return $invoice->fresh(['lines.account', 'customer']);
        });
    }

    /**
     * Open payable at cutover: Dr Opening Balance Equity / Cr AP control.
     *
     * @param array<string, mixed> $data
     */
    public function postOpeningPayable(array $data, int $userId): VendorInvoice
    {
        $vendor = Vendor::query()->findOrFail($data['vendor_id']);
        $payableId = $this->resolvePostable(
            $vendor->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount(),
        );
        $equityId = $this->equityAccount($data['equity_account_id'] ?? null);
        $amount = round((float) $data['outstanding_amount'], 2);
        $cutover = Carbon::parse($data['cutover_date']);

        return DB::transaction(function () use ($data, $vendor, $payableId, $equityId, $amount, $cutover, $userId): VendorInvoice {
            $invoice = VendorInvoice::query()->create([
                'invoice_number' => $data['invoice_number'],
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? $data['invoice_number'],
                'vendor_id' => $vendor->id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? $data['invoice_date'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? 'Opening balance',
                'currency_code' => 'SAR',
                'exchange_rate' => 1,
                'subtotal' => $amount,
                'tax_amount' => 0,
                'total_amount' => $amount,
                'amount_paid' => 0,
                'status' => VendorInvoiceStatus::Posted,
                'is_opening' => true,
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            $invoice->lines()->create([
                'line_no' => 1,
                'account_id' => $equityId,
                'description' => 'Opening balance',
                'amount' => $amount,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'line_total' => $amount,
            ]);

            $rows = $this->posting->post($invoice, $cutover, [
                new PostingLine($equityId, $amount, 0.0, 'SAR', 1.0, null, 'Opening balance equity'),
                new PostingLine($payableId, 0.0, $amount, 'SAR', 1.0, null, 'Opening AP — '.$vendor->name),
            ], $userId);

            $invoice->update(['fiscal_period_id' => $rows->first()->fiscal_period_id, 'batch_number' => $rows->first()->batch_number]);

            return $invoice->fresh(['lines.account', 'vendor']);
        });
    }

    private function equityAccount(?int $given): int
    {
        if ($given) {
            return $this->resolvePostable($given);
        }

        $obe = ChartOfAccount::query()->where('code', OpeningBalanceEquitySeeder::CODE)->first();

        return $obe?->id ?? throw new FinanceRuleException('No Opening Balance Equity account ('.OpeningBalanceEquitySeeder::CODE.') is configured.');
    }
}
