<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PettyCashStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\CustomerAdvance;
use App\Models\CustomerInvoice;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * On-account customer receipts. Receive money not tied to an invoice (Dr bank /
 * Cr customer-advances), then apply the balance to invoices (Dr advances / Cr AR).
 */
final class CustomerAdvanceService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): CustomerAdvance
    {
        $date = Carbon::parse($data['receipt_date']);

        return CustomerAdvance::query()->create([
            'advance_number' => $this->numbers->next('customer_advance', $date),
            'customer_id' => $data['customer_id'],
            'receipt_date' => $date->toDateString(),
            'bank_account_id' => $data['bank_account_id'],
            'advance_account_id' => $data['advance_account_id'],
            'payment_method' => $data['payment_method'] ?? 'bank_transfer',
            'reference' => $data['reference'] ?? null,
            'currency_code' => 'SAR',
            'amount' => round((float) $data['amount'], 2),
            'applied_amount' => 0,
            'status' => PettyCashStatus::Draft,
            'created_by' => $userId,
        ]);
    }

    /** Dr bank / Cr customer-advances. */
    public function post(CustomerAdvance $advance, int $userId): CustomerAdvance
    {
        if ($advance->status !== PettyCashStatus::Draft) {
            throw FinanceRuleException::invalidTransition($advance->status->value, PettyCashStatus::Posted->value);
        }
        $bankId = $this->resolvePostable((int) $advance->bank_account_id);
        $advanceId = $this->resolvePostable((int) $advance->advance_account_id);

        return DB::transaction(function () use ($advance, $userId, $bankId, $advanceId): CustomerAdvance {
            $rows = $this->posting->post($advance, Carbon::parse($advance->receipt_date), [
                new PostingLine($bankId, (float) $advance->amount, 0.0, 'SAR', 1.0, null, $advance->advance_number),
                new PostingLine($advanceId, 0.0, (float) $advance->amount, 'SAR', 1.0, null, 'Customer advance — '.$advance->customer->name),
            ], $userId);

            $advance->update([
                'status' => PettyCashStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId, 'posted_at' => now(),
            ]);

            return $advance->fresh('customer');
        });
    }

    /** Apply part of a posted advance to an invoice: Dr advances / Cr AR. */
    public function apply(CustomerAdvance $advance, int $invoiceId, float $amount, int $userId): CustomerAdvance
    {
        if ($advance->status !== PettyCashStatus::Posted) {
            throw new FinanceRuleException('Only a posted advance can be applied.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $advance->unapplied()) {
            throw new FinanceRuleException('Apply amount exceeds the available advance balance.');
        }

        $invoice = CustomerInvoice::query()->findOrFail($invoiceId);
        if ($invoice->customer_id !== $advance->customer_id) {
            throw new FinanceRuleException('The invoice belongs to a different customer.');
        }
        if ($amount > $invoice->balanceDue()) {
            throw new FinanceRuleException('Apply amount exceeds the invoice balance.');
        }

        $advanceId = $this->resolvePostable((int) $advance->advance_account_id);
        $arId = $this->resolvePostable($invoice->customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount());

        return DB::transaction(function () use ($advance, $invoice, $amount, $advanceId, $arId, $userId): CustomerAdvance {
            $this->posting->post($advance, Carbon::today(), [
                new PostingLine($advanceId, $amount, 0.0, 'SAR', 1.0, null, 'Apply advance '.$advance->advance_number),
                new PostingLine($arId, 0.0, $amount, 'SAR', 1.0, null, 'Applied to '.$invoice->invoice_number),
            ], $userId);

            $invoice->increment('amount_paid', $amount);
            $advance->increment('applied_amount', $amount);

            return $advance->fresh('customer');
        });
    }
}
