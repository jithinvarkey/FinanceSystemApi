<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerInvoiceStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\TaxCode;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.6–P4.10 — Customer (sales / commission) invoice lifecycle.
 *
 * Draft (with Saudi VAT 15% computed per line) -> submit (credit-limit check
 * P4.2 + maker-checker approval via P0.4) -> post. Posting writes, through the
 * single GL gateway:
 *   Dr  customer receivable (gross)
 *   Cr  revenue / commission lines (net)
 *   Cr  output VAT
 */
final class CustomerInvoiceService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): CustomerInvoice
    {
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($data, $lines, $totals, $userId): CustomerInvoice {
            $invoiceDate = Carbon::parse($data['invoice_date']);

            $invoice = CustomerInvoice::query()->create([
                'invoice_number' => $this->numbers->next('customer_invoice', $invoiceDate),
                'customer_id' => $data['customer_id'],
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => CustomerInvoiceStatus::Draft,
                'created_by' => $userId,
            ]);

            $invoice->lines()->createMany($lines);

            return $invoice->load('lines.account');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(CustomerInvoice $invoice, array $data): CustomerInvoice
    {
        if (! $invoice->status->isEditable()) {
            throw FinanceRuleException::notEditable($invoice->status->value);
        }

        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($invoice, $data, $lines, $totals): CustomerInvoice {
            $invoice->update([
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? $invoice->currency_code,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => CustomerInvoiceStatus::Draft,
                'rejection_reason' => null,
            ]);

            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);

            return $invoice->load('lines.account');
        });
    }

    /**
     * Send the invoice for maker-checker approval (routed on total amount).
     * Enforces the customer's credit limit first (P4.2).
     */
    public function submit(CustomerInvoice $invoice, int $userId): CustomerInvoice
    {
        if (! $invoice->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($invoice->status->value, CustomerInvoiceStatus::PendingApproval->value);
        }

        $this->assertWithinCreditLimit($invoice);

        return DB::transaction(function () use ($invoice, $userId): CustomerInvoice {
            $this->approvals->initiate($invoice, 'customer_invoice', (float) $invoice->total_amount, $userId);
            $invoice->update(['status' => CustomerInvoiceStatus::PendingApproval, 'rejection_reason' => null]);

            return $invoice->fresh('lines.account');
        });
    }

    public function deleteDraft(CustomerInvoice $invoice): void
    {
        if ($invoice->status !== CustomerInvoiceStatus::Draft) {
            throw FinanceRuleException::notEditable($invoice->status->value);
        }

        $invoice->delete();
    }

    /**
     * P4.7 — post the approved invoice to the general ledger.
     */
    public function post(CustomerInvoice $invoice, int $userId): CustomerInvoice
    {
        if ($invoice->status !== CustomerInvoiceStatus::Approved) {
            throw FinanceRuleException::invalidTransition($invoice->status->value, CustomerInvoiceStatus::Posted->value);
        }

        $receivableId = $this->resolvePostable(
            $invoice->customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount(),
        );

        return DB::transaction(function () use ($invoice, $userId, $receivableId): CustomerInvoice {
            $invoice->loadMissing('lines');
            $lines = [];

            // Receivable (gross debit).
            $lines[] = new PostingLine(
                accountId: $receivableId,
                debit: (float) $invoice->total_amount,
                credit: 0.0,
                currencyCode: $invoice->currency_code,
                exchangeRate: (float) $invoice->exchange_rate,
                costCenterId: null,
                description: 'AR — '.$invoice->customer->name,
            );

            // Revenue / commission legs (net credit).
            foreach ($invoice->lines as $line) {
                $lines[] = new PostingLine(
                    accountId: $line->account_id,
                    debit: 0.0,
                    credit: (float) $line->amount,
                    currencyCode: $invoice->currency_code,
                    exchangeRate: (float) $invoice->exchange_rate,
                    costCenterId: $line->cost_center_id,
                    description: $line->description ?? $invoice->description,
                );
            }

            // Output VAT legs, grouped by the tax code's output account (credit).
            foreach ($this->vatByAccount($invoice) as $accountId => $tax) {
                $lines[] = new PostingLine(
                    accountId: $accountId,
                    debit: 0.0,
                    credit: round($tax, 2),
                    currencyCode: $invoice->currency_code,
                    exchangeRate: (float) $invoice->exchange_rate,
                    costCenterId: null,
                    description: 'Output VAT',
                );
            }

            $rows = $this->posting->post($invoice, Carbon::parse($invoice->invoice_date), $lines, $userId);

            $invoice->update([
                'status' => CustomerInvoiceStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $invoice->fresh('lines.account');
        });
    }

    /**
     * Write off the outstanding balance of a posted invoice as a bad debt:
     * Dr bad-debt expense / Cr AR for the balance due, and close the invoice.
     */
    public function writeOff(CustomerInvoice $invoice, int $badDebtAccountId, int $userId): CustomerInvoice
    {
        if ($invoice->status !== CustomerInvoiceStatus::Posted) {
            throw new FinanceRuleException('Only a posted invoice can be written off.');
        }
        $balance = $invoice->balanceDue();
        if ($balance <= 0) {
            throw new FinanceRuleException('This invoice has no outstanding balance to write off.');
        }

        $expenseId = $this->resolvePostable($badDebtAccountId);
        $receivableId = $this->resolvePostable($invoice->customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount());

        return DB::transaction(function () use ($invoice, $balance, $expenseId, $receivableId, $userId): CustomerInvoice {
            $this->posting->post($invoice, Carbon::today(), [
                new PostingLine($expenseId, $balance, 0.0, $invoice->currency_code, (float) $invoice->exchange_rate, null, 'Bad debt write-off — '.$invoice->invoice_number),
                new PostingLine($receivableId, 0.0, $balance, $invoice->currency_code, (float) $invoice->exchange_rate, null, 'AR write-off'),
            ], $userId);

            $invoice->update(['amount_paid' => round((float) $invoice->total_amount, 2)]);

            return $invoice->fresh('lines.account');
        });
    }

    // ----- internals -----

    /**
     * P4.2 — block submission if it would push the customer's outstanding
     * receivable above the credit limit. A limit of 0 means "no limit set".
     *
     * @throws FinanceRuleException
     */
    private function assertWithinCreditLimit(CustomerInvoice $invoice): void
    {
        $customer = $invoice->customer ?? Customer::query()->find($invoice->customer_id);
        $limit = (float) ($customer?->credit_limit ?? 0);

        if ($limit <= 0) {
            return; // no limit configured
        }

        $exposure = round($this->currentExposure($invoice->customer_id, $invoice->id) + (float) $invoice->total_amount, 2);

        if ($exposure > $limit + 0.001) {
            throw FinanceRuleException::creditLimitExceeded(
                number_format($limit, 2, '.', ''),
                number_format($exposure, 2, '.', ''),
            );
        }
    }

    /**
     * Outstanding receivable already committed for a customer: posted invoices'
     * balance still due, plus the gross of in-flight (pending/approved) invoices.
     */
    private function currentExposure(int $customerId, ?int $exceptInvoiceId = null): float
    {
        $invoices = CustomerInvoice::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['pending_approval', 'approved', 'posted'])
            ->when($exceptInvoiceId !== null, fn ($q) => $q->whereKeyNot($exceptInvoiceId))
            ->get(['status', 'total_amount', 'amount_paid']);

        $sum = 0.0;
        foreach ($invoices as $inv) {
            $sum += $inv->status === CustomerInvoiceStatus::Posted
                ? (float) $inv->balanceDue()
                : (float) $inv->total_amount;
        }

        return round($sum, 2);
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
     */
    private function totals(array $lines): array
    {
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $tax = round(array_sum(array_column($lines, 'tax_amount')), 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
    }

    /**
     * Tax grouped by the postable output-VAT account it posts to.
     *
     * @return array<int, float>
     */
    private function vatByAccount(CustomerInvoice $invoice): array
    {
        $taxAccounts = TaxCode::query()->whereNotNull('output_account_id')->pluck('output_account_id', 'id');
        $out = [];

        foreach ($invoice->lines as $line) {
            if ((float) $line->tax_amount <= 0 || $line->tax_code_id === null) {
                continue;
            }
            $outputAccount = $taxAccounts[$line->tax_code_id] ?? null;
            if ($outputAccount === null) {
                continue;
            }
            $resolved = $this->resolvePostable((int) $outputAccount);
            $out[$resolved] = ($out[$resolved] ?? 0) + (float) $line->tax_amount;
        }

        return $out;
    }
}
