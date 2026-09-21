<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VendorInvoiceStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\TaxCode;
use App\Models\VendorInvoice;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P3.6–P3.10 — Vendor invoice lifecycle.
 *
 * Draft (with Saudi VAT 15% computed per line) -> submit (maker-checker
 * approval via P0.4) -> post. Posting writes, through the single GL gateway:
 *   Dr  expense/asset lines (net)
 *   Dr  input VAT
 *   Cr  vendor payable (gross)
 */
final class VendorInvoiceService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): VendorInvoice
    {
        $this->assertNotDuplicate((int) $data['vendor_id'], $data['vendor_invoice_no'] ?? null);

        $reverseCharge = (bool) ($data['reverse_charge'] ?? false);
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines, $reverseCharge);

        return DB::transaction(function () use ($data, $lines, $totals, $reverseCharge, $userId): VendorInvoice {
            $invoiceDate = Carbon::parse($data['invoice_date']);

            $invoice = VendorInvoice::query()->create([
                'invoice_number' => $this->numbers->next('vendor_invoice', $invoiceDate),
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'vendor_id' => $data['vendor_id'],
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'reverse_charge' => $reverseCharge,
                'status' => VendorInvoiceStatus::Draft,
                'created_by' => $userId,
            ]);

            $invoice->lines()->createMany($lines);

            return $invoice->load('lines.account');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(VendorInvoice $invoice, array $data): VendorInvoice
    {
        if (! $invoice->status->isEditable()) {
            throw FinanceRuleException::notEditable($invoice->status->value);
        }

        $this->assertNotDuplicate((int) $invoice->vendor_id, $data['vendor_invoice_no'] ?? null, $invoice->id);

        $reverseCharge = (bool) ($data['reverse_charge'] ?? $invoice->reverse_charge);
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines, $reverseCharge);

        return DB::transaction(function () use ($invoice, $data, $lines, $totals, $reverseCharge): VendorInvoice {
            $invoice->update([
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? $invoice->currency_code,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'reverse_charge' => $reverseCharge,
                'status' => VendorInvoiceStatus::Draft,
                'rejection_reason' => null,
            ]);

            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);

            return $invoice->load('lines.account');
        });
    }

    /** Send the invoice for maker-checker approval (routed on total amount). */
    public function submit(VendorInvoice $invoice, int $userId): VendorInvoice
    {
        if (! $invoice->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($invoice->status->value, VendorInvoiceStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($invoice, $userId): VendorInvoice {
            $this->approvals->initiate($invoice, 'vendor_invoice', (float) $invoice->total_amount, $userId);
            $invoice->update(['status' => VendorInvoiceStatus::PendingApproval, 'rejection_reason' => null]);

            return $invoice->fresh('lines.account');
        });
    }

    public function deleteDraft(VendorInvoice $invoice): void
    {
        if ($invoice->status !== VendorInvoiceStatus::Draft) {
            throw FinanceRuleException::notEditable($invoice->status->value);
        }

        $invoice->delete();
    }

    /**
     * P3.10 — post the approved invoice to the general ledger.
     */
    public function post(VendorInvoice $invoice, int $userId): VendorInvoice
    {
        if ($invoice->status !== VendorInvoiceStatus::Approved) {
            throw FinanceRuleException::invalidTransition($invoice->status->value, VendorInvoiceStatus::Posted->value);
        }

        $payableId = $this->resolvePostable(
            $invoice->vendor->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount(),
        );

        return DB::transaction(function () use ($invoice, $userId, $payableId): VendorInvoice {
            $invoice->loadMissing('lines');
            $lines = [];

            // Expense / asset legs (net).
            foreach ($invoice->lines as $line) {
                $lines[] = new PostingLine(
                    accountId: $line->account_id,
                    debit: (float) $line->amount,
                    credit: 0.0,
                    currencyCode: $invoice->currency_code,
                    exchangeRate: (float) $invoice->exchange_rate,
                    costCenterId: $line->cost_center_id,
                    description: $line->description ?? $invoice->description,
                );
            }

            // Input VAT legs, grouped by the tax code's input account.
            foreach ($this->vatByAccount($invoice, 'input_account_id') as $accountId => $tax) {
                $lines[] = new PostingLine(
                    accountId: $accountId,
                    debit: round($tax, 2),
                    credit: 0.0,
                    currencyCode: $invoice->currency_code,
                    exchangeRate: (float) $invoice->exchange_rate,
                    costCenterId: null,
                    description: $invoice->reverse_charge ? 'Input VAT (reverse charge)' : 'Input VAT',
                );
            }

            // F14 — reverse charge (import): the vendor charges no VAT, so the
            // buyer self-assesses output VAT mirroring the input VAT. Net-zero
            // cash; both legs flow into the ZATCA return.
            if ($invoice->reverse_charge) {
                foreach ($this->vatByAccount($invoice, 'output_account_id') as $accountId => $tax) {
                    $lines[] = new PostingLine(
                        accountId: $accountId,
                        debit: 0.0,
                        credit: round($tax, 2),
                        currencyCode: $invoice->currency_code,
                        exchangeRate: (float) $invoice->exchange_rate,
                        costCenterId: null,
                        description: 'Output VAT (reverse charge)',
                    );
                }
            }

            // Payable credit. For a normal bill this is gross (subtotal + VAT);
            // for a reverse-charge import total_amount already equals the net.
            $lines[] = new PostingLine(
                accountId: $payableId,
                debit: 0.0,
                credit: (float) $invoice->total_amount,
                currencyCode: $invoice->currency_code,
                exchangeRate: (float) $invoice->exchange_rate,
                costCenterId: null,
                description: 'AP — '.$invoice->vendor->name,
            );

            $rows = $this->posting->post($invoice, Carbon::parse($invoice->invoice_date), $lines, $userId);

            $invoice->update([
                'status' => VendorInvoiceStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $invoice->fresh('lines.account');
        });
    }

    /**
     * Take the vendor's early-payment (settlement) discount on a posted bill,
     * if still within the discount window: Dr AP / Cr discount income, and
     * reduce the invoice balance by the discount. Returns the discount taken.
     */
    public function takeEarlyPaymentDiscount(VendorInvoice $invoice, int $discountIncomeAccountId, int $userId, ?Carbon $asOf = null): float
    {
        if ($invoice->status !== VendorInvoiceStatus::Posted) {
            throw new FinanceRuleException('Only a posted bill can take a settlement discount.');
        }
        $vendor = $invoice->vendor;
        $rate = (float) $vendor->settlement_discount_percent;
        $days = (int) $vendor->settlement_discount_days;
        if ($rate <= 0 || $days <= 0) {
            throw new FinanceRuleException('This vendor has no early-payment discount terms.');
        }

        $asOf = $asOf ?? Carbon::today();
        $deadline = Carbon::parse((string) $invoice->invoice_date)->addDays($days);
        if ($asOf->gt($deadline)) {
            throw new FinanceRuleException("The discount window closed on {$deadline->toDateString()}.");
        }

        $balance = $invoice->balanceDue();
        $discount = round(min($balance, (float) $invoice->total_amount * $rate / 100), 2);
        if ($discount <= 0) {
            throw new FinanceRuleException('No discount available on this bill.');
        }

        $payableId = $this->resolvePostable($vendor->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount());
        $incomeId = $this->resolvePostable($discountIncomeAccountId);

        DB::transaction(function () use ($invoice, $discount, $payableId, $incomeId, $userId, $asOf): void {
            $this->posting->post($invoice, $asOf, [
                new PostingLine($payableId, $discount, 0.0, $invoice->currency_code, (float) $invoice->exchange_rate, null, 'Settlement discount — '.$invoice->invoice_number),
                new PostingLine($incomeId, 0.0, $discount, $invoice->currency_code, (float) $invoice->exchange_rate, null, 'Early-payment discount income'),
            ], $userId);

            $invoice->increment('amount_paid', $discount);
        });

        return $discount;
    }

    /**
     * Apply withholding tax to a posted bill at the vendor's WHT rate (on the
     * net / taxable base): Dr AP / Cr WHT payable, reducing the bill balance.
     * The WHT-payable liability accumulates for remittance to ZATCA.
     */
    public function applyWithholding(VendorInvoice $invoice, int $whtPayableAccountId, int $userId): float
    {
        if ($invoice->status !== VendorInvoiceStatus::Posted) {
            throw new FinanceRuleException('Only a posted bill can have withholding applied.');
        }
        if ((float) $invoice->withholding_amount > 0) {
            throw new FinanceRuleException('Withholding has already been applied to this bill.');
        }
        $rate = (float) $invoice->vendor->wht_rate;
        if ($rate <= 0) {
            throw new FinanceRuleException('This vendor has no withholding-tax rate set.');
        }

        $wht = round((float) $invoice->subtotal * $rate / 100, 2);
        if ($wht <= 0 || $wht > $invoice->balanceDue()) {
            throw new FinanceRuleException('The withholding amount is invalid for this bill.');
        }

        $payableId = $this->resolvePostable($invoice->vendor->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount());
        $whtId = $this->resolvePostable($whtPayableAccountId);

        DB::transaction(function () use ($invoice, $wht, $payableId, $whtId, $userId): void {
            $this->posting->post($invoice, Carbon::today(), [
                new PostingLine($payableId, $wht, 0.0, $invoice->currency_code, (float) $invoice->exchange_rate, null, 'Withholding tax — '.$invoice->invoice_number),
                new PostingLine($whtId, 0.0, $wht, $invoice->currency_code, (float) $invoice->exchange_rate, null, 'WHT payable to ZATCA'),
            ], $userId);

            $invoice->increment('amount_paid', $wht);
            $invoice->increment('withholding_amount', $wht);
        });

        return $wht;
    }

    /**
     * Withholding-tax report: bills with WHT applied, grouped per vendor.
     *
     * @return array{rows: list<array<string,mixed>>, total: string}
     */
    public function withholdingReport(?Carbon $from, ?Carbon $to): array
    {
        $rows = VendorInvoice::query()
            ->where('withholding_amount', '>', 0)
            ->when($from !== null, fn ($q) => $q->whereDate('invoice_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('invoice_date', '<=', $to->toDateString()))
            ->with('vendor:id,name,vendor_code,wht_rate')
            ->orderByDesc('id')->get()
            ->map(fn (VendorInvoice $i): array => [
                'vendor' => $i->vendor?->name,
                'vendor_code' => $i->vendor?->vendor_code,
                'invoice_number' => $i->invoice_number,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'base' => $i->subtotal,
                'wht_rate' => $i->vendor?->wht_rate,
                'withholding_amount' => $i->withholding_amount,
            ]);

        return ['rows' => $rows->all(), 'total' => number_format((float) $rows->sum('withholding_amount'), 2, '.', '')];
    }

    // ----- internals -----

    /** @throws FinanceRuleException */
    private function assertNotDuplicate(int $vendorId, ?string $vendorInvoiceNo, ?int $exceptId = null): void
    {
        if ($vendorInvoiceNo === null || $vendorInvoiceNo === '') {
            return;
        }

        $exists = VendorInvoice::query()
            ->where('vendor_id', $vendorId)
            ->where('vendor_invoice_no', $vendorInvoiceNo)
            ->where('status', '!=', VendorInvoiceStatus::Cancelled->value)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw FinanceRuleException::duplicateInvoice($vendorInvoiceNo);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private function normaliseLines(array $raw): array
    {
        if (count($raw) < 1) {
            throw FinanceRuleException::emptyJournal();
        }

        $taxRates = TaxCode::query()->pluck('rate', 'id');
        $lines = [];

        foreach (array_values($raw) as $i => $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            $taxCodeId = isset($line['tax_code_id']) ? (int) $line['tax_code_id'] : null;
            $taxRate = $taxCodeId !== null ? (float) ($taxRates[$taxCodeId] ?? 0) : 0.0;
            $taxAmount = round($amount * $taxRate / 100, 2);

            $lines[] = [
                'line_no' => $i + 1,
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => isset($line['cost_center_id']) ? (int) $line['cost_center_id'] : null,
                'description' => $line['description'] ?? null,
                'amount' => $amount,
                'tax_code_id' => $taxCodeId,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_total' => round($amount + $taxAmount, 2),
            ];
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{subtotal: float, tax: float, total: float}
     *
     * For a reverse-charge import the VAT is self-assessed (not billed by the
     * vendor), so the payable total is the net — the VAT washes out in the GL.
     */
    private function totals(array $lines, bool $reverseCharge = false): array
    {
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $tax = round(array_sum(array_column($lines, 'tax_amount')), 2);
        $total = $reverseCharge ? $subtotal : round($subtotal + $tax, 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total];
    }

    /**
     * Tax grouped by the postable VAT account it posts to — $column is
     * 'input_account_id' (recoverable input VAT) or 'output_account_id'
     * (self-assessed output VAT for reverse charge).
     *
     * @return array<int, float>
     */
    private function vatByAccount(VendorInvoice $invoice, string $column): array
    {
        $taxAccounts = TaxCode::query()->whereNotNull($column)->pluck($column, 'id');
        $out = [];

        foreach ($invoice->lines as $line) {
            if ((float) $line->tax_amount <= 0 || $line->tax_code_id === null) {
                continue;
            }
            $account = $taxAccounts[$line->tax_code_id] ?? null;
            if ($account === null) {
                continue;
            }
            $resolved = $this->resolvePostable((int) $account);
            $out[$resolved] = ($out[$resolved] ?? 0) + (float) $line->tax_amount;
        }

        return $out;
    }
}
