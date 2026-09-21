<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerInvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\CustomerInvoice;
use App\Models\Receipt;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.11–P4.14 — Customer receipt lifecycle.
 *
 * A receipt settles one or more posted invoices of a single customer:
 * draft -> submit (maker-checker approval) -> post. Posting writes:
 *   Dr  bank/cash          (funds received)
 *   Cr  customer receivable (reduce AR)
 * and credits each invoice's amount_paid.
 */
final class ReceiptService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): Receipt
    {
        $allocations = $this->validatedAllocations((int) $data['customer_id'], $data['allocations'] ?? []);
        $amount = round(array_sum(array_column($allocations, 'amount')), 2);

        return DB::transaction(function () use ($data, $allocations, $amount, $userId): Receipt {
            $date = Carbon::parse($data['receipt_date']);

            $receipt = Receipt::query()->create([
                'receipt_number' => $this->numbers->next('receipt', $date),
                'customer_id' => $data['customer_id'],
                'receipt_date' => $date->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'amount' => $amount,
                'status' => ReceiptStatus::Draft,
                'created_by' => $userId,
            ]);

            $receipt->allocations()->createMany($allocations);

            return $receipt->load(['allocations.invoice', 'customer', 'bankAccount']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Receipt $receipt, array $data): Receipt
    {
        if (! $receipt->status->canSubmit()) {
            throw FinanceRuleException::notEditable($receipt->status->value);
        }

        $allocations = $this->validatedAllocations((int) $data['customer_id'], $data['allocations'] ?? []);
        $amount = round(array_sum(array_column($allocations, 'amount')), 2);

        return DB::transaction(function () use ($receipt, $data, $allocations, $amount): Receipt {
            $receipt->update([
                'customer_id' => $data['customer_id'],
                'receipt_date' => Carbon::parse($data['receipt_date'])->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'amount' => $amount,
                'status' => ReceiptStatus::Draft,
                'rejection_reason' => null,
            ]);

            $receipt->allocations()->delete();
            $receipt->allocations()->createMany($allocations);

            return $receipt->fresh(['allocations.invoice', 'customer', 'bankAccount']);
        });
    }

    public function submit(Receipt $receipt, int $userId): Receipt
    {
        if (! $receipt->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($receipt->status->value, ReceiptStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($receipt, $userId): Receipt {
            $this->approvals->initiate($receipt, 'receipt', (float) $receipt->amount, $userId);
            $receipt->update(['status' => ReceiptStatus::PendingApproval, 'rejection_reason' => null]);

            return $receipt->fresh(['allocations.invoice', 'customer', 'bankAccount']);
        });
    }

    public function deleteDraft(Receipt $receipt): void
    {
        if ($receipt->status !== ReceiptStatus::Draft) {
            throw FinanceRuleException::notEditable($receipt->status->value);
        }

        $receipt->delete();
    }

    /**
     * P4.14 — post the approved receipt: Dr bank → Cr receivable, and credit
     * each settled invoice's amount_paid.
     */
    public function post(Receipt $receipt, int $userId): Receipt
    {
        if ($receipt->status !== ReceiptStatus::Approved) {
            throw FinanceRuleException::invalidTransition($receipt->status->value, ReceiptStatus::Posted->value);
        }

        $receivableId = $this->resolvePostable(
            $receipt->customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount(),
        );
        $bankId = $this->resolvePostable((int) $receipt->bank_account_id);

        return DB::transaction(function () use ($receipt, $userId, $receivableId, $bankId): Receipt {
            $receipt->loadMissing('allocations');

            $lines = [
                new PostingLine(
                    accountId: $bankId,
                    debit: (float) $receipt->amount,
                    credit: 0.0,
                    currencyCode: $receipt->currency_code,
                    exchangeRate: (float) $receipt->exchange_rate,
                    costCenterId: null,
                    description: $receipt->receipt_number,
                ),
                new PostingLine(
                    accountId: $receivableId,
                    debit: 0.0,
                    credit: (float) $receipt->amount,
                    currencyCode: $receipt->currency_code,
                    exchangeRate: (float) $receipt->exchange_rate,
                    costCenterId: null,
                    description: 'Receipt from '.$receipt->customer->name,
                ),
            ];

            $rows = $this->posting->post($receipt, Carbon::parse($receipt->receipt_date), $lines, $userId);

            // Settle the invoices.
            foreach ($receipt->allocations as $allocation) {
                CustomerInvoice::query()->whereKey($allocation->customer_invoice_id)
                    ->increment('amount_paid', (float) $allocation->amount);
            }

            $receipt->update([
                'status' => ReceiptStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $receipt->fresh(['allocations.invoice', 'customer', 'bankAccount']);
        });
    }

    /**
     * Validate that every allocation targets a posted invoice of this customer
     * and does not exceed its outstanding balance.
     *
     * @param array<int, array<string, mixed>> $raw
     * @return list<array{customer_invoice_id: int, amount: float}>
     *
     * @throws FinanceRuleException
     */
    private function validatedAllocations(int $customerId, array $raw): array
    {
        if (count($raw) < 1) {
            throw new FinanceRuleException('Select at least one invoice to settle.');
        }

        $invoices = CustomerInvoice::query()
            ->whereIn('id', array_column($raw, 'customer_invoice_id'))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($raw as $row) {
            $invoice = $invoices->get((int) $row['customer_invoice_id']);
            $amount = round((float) $row['amount'], 2);

            if ($invoice === null || (int) $invoice->customer_id !== $customerId) {
                throw new FinanceRuleException('An allocated invoice does not belong to this customer.');
            }
            if ($invoice->status !== CustomerInvoiceStatus::Posted) {
                throw new FinanceRuleException("Invoice {$invoice->invoice_number} is not posted and cannot be settled.");
            }
            if ($amount <= 0 || $amount > $invoice->balanceDue() + 0.001) {
                throw new FinanceRuleException("Allocation for {$invoice->invoice_number} exceeds its outstanding balance.");
            }

            $out[] = ['customer_invoice_id' => $invoice->id, 'amount' => $amount];
        }

        return $out;
    }
}
