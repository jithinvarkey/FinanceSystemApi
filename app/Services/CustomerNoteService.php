<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerInvoiceStatus;
use App\Enums\NoteType;
use App\Exceptions\FinanceRuleException;
use App\Models\CustomerInvoice;
use App\Models\CustomerNote;
use App\Models\TaxCode;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AR credit & debit notes. Draft → submit (maker-checker) → post.
 *  - Credit note: Dr revenue (net) + Dr output VAT / Cr AR (gross) — reduces the
 *    customer's balance; applied to the original invoice (raises amount_paid).
 *  - Debit note: Dr AR (gross) / Cr revenue (net) + Cr output VAT — an extra charge.
 */
final class CustomerNoteService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): CustomerNote
    {
        $type = NoteType::from($data['note_type']);
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($data, $type, $lines, $totals, $userId): CustomerNote {
            $date = Carbon::parse($data['note_date']);
            $numberType = $type === NoteType::Credit ? 'customer_credit_note' : 'customer_debit_note';

            $note = CustomerNote::query()->create([
                'note_number' => $this->numbers->next($numberType, $date),
                'note_type' => $type,
                'customer_id' => $data['customer_id'],
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'note_date' => $date->toDateString(),
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => CustomerInvoiceStatus::Draft,
                'created_by' => $userId,
            ]);
            $note->lines()->createMany($lines);

            return $note->load('lines.account');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(CustomerNote $note, array $data): CustomerNote
    {
        if (! $note->status->isEditable()) {
            throw FinanceRuleException::notEditable($note->status->value);
        }
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($note, $data, $lines, $totals): CustomerNote {
            $note->update([
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'note_date' => $data['note_date'],
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? null,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => CustomerInvoiceStatus::Draft,
                'rejection_reason' => null,
            ]);
            $note->lines()->delete();
            $note->lines()->createMany($lines);

            return $note->fresh('lines.account');
        });
    }

    public function deleteDraft(CustomerNote $note): void
    {
        if ($note->status !== CustomerInvoiceStatus::Draft) {
            throw FinanceRuleException::notEditable($note->status->value);
        }
        $note->delete();
    }

    public function submit(CustomerNote $note, int $userId): CustomerNote
    {
        if (! $note->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($note->status->value, CustomerInvoiceStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($note, $userId): CustomerNote {
            $this->approvals->initiate($note, 'customer_invoice', (float) $note->total_amount, $userId);
            $note->update(['status' => CustomerInvoiceStatus::PendingApproval, 'rejection_reason' => null]);

            return $note->fresh('lines.account');
        });
    }

    public function post(CustomerNote $note, int $userId): CustomerNote
    {
        if ($note->status !== CustomerInvoiceStatus::Approved) {
            throw FinanceRuleException::invalidTransition($note->status->value, CustomerInvoiceStatus::Posted->value);
        }

        $arId = $this->resolvePostable($note->customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount());
        $credit = $note->note_type === NoteType::Credit;

        return DB::transaction(function () use ($note, $userId, $arId, $credit): CustomerNote {
            $note->loadMissing('lines');
            $lines = [];

            // Revenue legs: debit on a credit note (reversal), credit on a debit note.
            foreach ($note->lines as $line) {
                $lines[] = new PostingLine($line->account_id, $credit ? (float) $line->amount : 0.0, $credit ? 0.0 : (float) $line->amount, $note->currency_code, (float) $note->exchange_rate, null, $line->description ?? $note->description);
            }
            // Output VAT, grouped by account: debit on credit note, credit on debit note.
            foreach ($this->vatByAccount($note) as $accountId => $tax) {
                $lines[] = new PostingLine($accountId, $credit ? round($tax, 2) : 0.0, $credit ? 0.0 : round($tax, 2), $note->currency_code, (float) $note->exchange_rate, null, 'Output VAT');
            }
            // AR control: credit on a credit note (reduces AR), debit on a debit note.
            $lines[] = new PostingLine($arId, $credit ? 0.0 : (float) $note->total_amount, $credit ? (float) $note->total_amount : 0.0, $note->currency_code, (float) $note->exchange_rate, null, ($credit ? 'AR credit — ' : 'AR debit — ').$note->customer->name);

            $rows = $this->posting->post($note, Carbon::parse($note->note_date), $lines, $userId);

            // A credit note applied to its invoice reduces that invoice's balance.
            if ($credit && $note->original_invoice_id) {
                $inv = CustomerInvoice::query()->find($note->original_invoice_id);
                if ($inv) {
                    $inv->update(['amount_paid' => round((float) $inv->amount_paid + (float) $note->total_amount, 2)]);
                }
            }

            $note->update([
                'status' => CustomerInvoiceStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $note->fresh('lines.account');
        });
    }

    /**
     * @param array<int, array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private function normaliseLines(array $raw): array
    {
        if (count($raw) < 1) {
            throw new FinanceRuleException('A note needs at least one line.');
        }
        $taxRates = TaxCode::query()->pluck('rate', 'id');
        $lines = [];
        foreach (array_values($raw) as $i => $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            $taxCodeId = isset($line['tax_code_id']) ? (int) $line['tax_code_id'] : null;
            $taxRate = $taxCodeId !== null ? (float) ($taxRates[$taxCodeId] ?? 0) : 0.0;
            $taxAmount = round($amount * $taxRate / 100, 2);
            $lines[] = [
                'line_no' => $i + 1, 'account_id' => (int) $line['account_id'], 'description' => $line['description'] ?? null,
                'amount' => $amount, 'tax_code_id' => $taxCodeId, 'tax_rate' => $taxRate, 'tax_amount' => $taxAmount, 'line_total' => round($amount + $taxAmount, 2),
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

    /** @return array<int, float> */
    private function vatByAccount(CustomerNote $note): array
    {
        $taxAccounts = TaxCode::query()->whereNotNull('output_account_id')->pluck('output_account_id', 'id');
        $out = [];
        foreach ($note->lines as $line) {
            if ((float) $line->tax_amount <= 0 || $line->tax_code_id === null) {
                continue;
            }
            $acc = $taxAccounts[$line->tax_code_id] ?? null;
            if ($acc === null) {
                continue;
            }
            $resolved = $this->resolvePostable((int) $acc);
            $out[$resolved] = ($out[$resolved] ?? 0) + (float) $line->tax_amount;
        }

        return $out;
    }
}
