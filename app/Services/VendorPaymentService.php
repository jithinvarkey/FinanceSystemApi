<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VendorInvoiceStatus;
use App\Enums\VendorPaymentStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P3.11–P3.15 — Vendor payment lifecycle.
 *
 * A payment settles one or more posted invoices of a single vendor:
 * draft -> submit (maker-checker approval) -> post. Posting writes:
 *   Dr  vendor payable (reduce AP)
 *   Cr  bank/cash       (reduce funds)
 * and credits each invoice's amount_paid.
 */
final class VendorPaymentService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): VendorPayment
    {
        $allocations = $this->validatedAllocations((int) $data['vendor_id'], $data['allocations'] ?? []);
        $amount = round(array_sum(array_column($allocations, 'amount')), 2);

        return DB::transaction(function () use ($data, $allocations, $amount, $userId): VendorPayment {
            $date = Carbon::parse($data['payment_date']);

            $payment = VendorPayment::query()->create([
                'payment_number' => $this->numbers->next('vendor_payment', $date),
                'vendor_id' => $data['vendor_id'],
                'payment_date' => $date->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'amount' => $amount,
                'status' => VendorPaymentStatus::Draft,
                'created_by' => $userId,
            ]);

            $payment->allocations()->createMany($allocations);

            return $payment->load(['allocations.invoice', 'vendor', 'bankAccount']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(VendorPayment $payment, array $data): VendorPayment
    {
        if (! $payment->status->canSubmit()) {
            throw FinanceRuleException::notEditable($payment->status->value);
        }

        $allocations = $this->validatedAllocations((int) $data['vendor_id'], $data['allocations'] ?? []);
        $amount = round(array_sum(array_column($allocations, 'amount')), 2);

        return DB::transaction(function () use ($payment, $data, $allocations, $amount): VendorPayment {
            $payment->update([
                'vendor_id' => $data['vendor_id'],
                'payment_date' => Carbon::parse($data['payment_date'])->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'amount' => $amount,
                'status' => VendorPaymentStatus::Draft,
                'rejection_reason' => null,
            ]);

            $payment->allocations()->delete();
            $payment->allocations()->createMany($allocations);

            return $payment->fresh(['allocations.invoice', 'vendor', 'bankAccount']);
        });
    }

    public function submit(VendorPayment $payment, int $userId): VendorPayment
    {
        if (! $payment->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($payment->status->value, VendorPaymentStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($payment, $userId): VendorPayment {
            $this->approvals->initiate($payment, 'vendor_payment', (float) $payment->amount, $userId);
            $payment->update(['status' => VendorPaymentStatus::PendingApproval, 'rejection_reason' => null]);

            return $payment->fresh(['allocations.invoice', 'vendor', 'bankAccount']);
        });
    }

    public function deleteDraft(VendorPayment $payment): void
    {
        if ($payment->status !== VendorPaymentStatus::Draft) {
            throw FinanceRuleException::notEditable($payment->status->value);
        }

        $payment->delete();
    }

    /**
     * P3.15 — post the approved payment: Dr vendor payable → Cr bank, and
     * credit each settled invoice's amount_paid.
     */
    public function post(VendorPayment $payment, int $userId): VendorPayment
    {
        if ($payment->status !== VendorPaymentStatus::Approved) {
            throw FinanceRuleException::invalidTransition($payment->status->value, VendorPaymentStatus::Posted->value);
        }

        $payableId = $this->resolvePostable(
            $payment->vendor->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount(),
        );
        $bankId = $this->resolvePostable((int) $payment->bank_account_id);

        return DB::transaction(function () use ($payment, $userId, $payableId, $bankId): VendorPayment {
            $payment->loadMissing('allocations');

            $lines = [
                new PostingLine(
                    accountId: $payableId,
                    debit: (float) $payment->amount,
                    credit: 0.0,
                    currencyCode: $payment->currency_code,
                    exchangeRate: (float) $payment->exchange_rate,
                    costCenterId: null,
                    description: 'Payment to '.$payment->vendor->name,
                ),
                new PostingLine(
                    accountId: $bankId,
                    debit: 0.0,
                    credit: (float) $payment->amount,
                    currencyCode: $payment->currency_code,
                    exchangeRate: (float) $payment->exchange_rate,
                    costCenterId: null,
                    description: $payment->payment_number,
                ),
            ];

            $rows = $this->posting->post($payment, Carbon::parse($payment->payment_date), $lines, $userId);

            // Settle the invoices.
            foreach ($payment->allocations as $allocation) {
                VendorInvoice::query()->whereKey($allocation->vendor_invoice_id)
                    ->increment('amount_paid', (float) $allocation->amount);
            }

            $payment->update([
                'status' => VendorPaymentStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $payment->fresh(['allocations.invoice', 'vendor', 'bankAccount']);
        });
    }

    /**
     * Validate that every allocation targets a posted invoice of this vendor
     * and does not exceed its outstanding balance.
     *
     * @param array<int, array<string, mixed>> $raw
     * @return list<array{vendor_invoice_id: int, amount: float}>
     *
     * @throws FinanceRuleException
     */
    private function validatedAllocations(int $vendorId, array $raw): array
    {
        if (count($raw) < 1) {
            throw new FinanceRuleException('Select at least one invoice to pay.');
        }

        $invoices = VendorInvoice::query()
            ->whereIn('id', array_column($raw, 'vendor_invoice_id'))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($raw as $row) {
            $invoice = $invoices->get((int) $row['vendor_invoice_id']);
            $amount = round((float) $row['amount'], 2);

            if ($invoice === null || (int) $invoice->vendor_id !== $vendorId) {
                throw new FinanceRuleException('An allocated invoice does not belong to this vendor.');
            }
            if ($invoice->status !== VendorInvoiceStatus::Posted) {
                throw new FinanceRuleException("Invoice {$invoice->invoice_number} is not posted and cannot be paid.");
            }
            if ($amount <= 0 || $amount > $invoice->balanceDue() + 0.001) {
                throw new FinanceRuleException("Allocation for {$invoice->invoice_number} exceeds its outstanding balance.");
            }

            $out[] = ['vendor_invoice_id' => $invoice->id, 'amount' => $amount];
        }

        return $out;
    }
}
