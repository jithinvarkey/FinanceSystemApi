<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NoteType;
use App\Enums\VendorInvoiceStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\TaxCode;
use App\Models\VendorInvoice;
use App\Models\VendorNote;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AP credit & debit notes.
 *  - Credit note: Dr AP (gross) / Cr expense (net) + Cr input VAT — reduces the
 *    payable; applied to the original invoice (raises amount_paid).
 *  - Debit note: Dr expense (net) + Dr input VAT / Cr AP (gross) — extra charge.
 */
final class VendorNoteService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): VendorNote
    {
        $type = NoteType::from($data['note_type']);
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($data, $type, $lines, $totals, $userId): VendorNote {
            $date = Carbon::parse($data['note_date']);
            $numberType = $type === NoteType::Credit ? 'vendor_credit_note' : 'vendor_debit_note';

            $note = VendorNote::query()->create([
                'note_number' => $this->numbers->next($numberType, $date),
                'note_type' => $type,
                'vendor_id' => $data['vendor_id'],
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'note_date' => $date->toDateString(),
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => VendorInvoiceStatus::Draft,
                'created_by' => $userId,
            ]);
            $note->lines()->createMany($lines);

            return $note->load('lines.account');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(VendorNote $note, array $data): VendorNote
    {
        if (! $note->status->isEditable()) {
            throw FinanceRuleException::notEditable($note->status->value);
        }
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($note, $data, $lines, $totals): VendorNote {
            $note->update([
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'note_date' => $data['note_date'],
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? null,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => VendorInvoiceStatus::Draft,
                'rejection_reason' => null,
            ]);
            $note->lines()->delete();
            $note->lines()->createMany($lines);

            return $note->fresh('lines.account');
        });
    }

    public function deleteDraft(VendorNote $note): void
    {
        if ($note->status !== VendorInvoiceStatus::Draft) {
            throw FinanceRuleException::notEditable($note->status->value);
        }
        $note->delete();
    }

    public function submit(VendorNote $note, int $userId): VendorNote
    {
        if (! $note->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($note->status->value, VendorInvoiceStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($note, $userId): VendorNote {
            $this->approvals->initiate($note, 'vendor_invoice', (float) $note->total_amount, $userId);
            $note->update(['status' => VendorInvoiceStatus::PendingApproval, 'rejection_reason' => null]);

            return $note->fresh('lines.account');
        });
    }

    public function post(VendorNote $note, int $userId): VendorNote
    {
        if ($note->status !== VendorInvoiceStatus::Approved) {
            throw FinanceRuleException::invalidTransition($note->status->value, VendorInvoiceStatus::Posted->value);
        }

        $apId = $this->resolvePostable($note->vendor->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount());
        $credit = $note->note_type === NoteType::Credit;

        return DB::transaction(function () use ($note, $userId, $apId, $credit): VendorNote {
            $note->loadMissing('lines');
            $lines = [];

            // Expense legs: credit on a credit note (reversal), debit on a debit note.
            foreach ($note->lines as $line) {
                $lines[] = new PostingLine($line->account_id, $credit ? 0.0 : (float) $line->amount, $credit ? (float) $line->amount : 0.0, $note->currency_code, (float) $note->exchange_rate, null, $line->description ?? $note->description);
            }
            // Input VAT, grouped by account: credit on credit note, debit on debit note.
            foreach ($this->vatByAccount($note) as $accountId => $tax) {
                $lines[] = new PostingLine($accountId, $credit ? 0.0 : round($tax, 2), $credit ? round($tax, 2) : 0.0, $note->currency_code, (float) $note->exchange_rate, null, 'Input VAT');
            }
            // AP control: debit on a credit note (reduces AP), credit on a debit note.
            $lines[] = new PostingLine($apId, $credit ? (float) $note->total_amount : 0.0, $credit ? 0.0 : (float) $note->total_amount, $note->currency_code, (float) $note->exchange_rate, null, ($credit ? 'AP debit — ' : 'AP credit — ').$note->vendor->name);

            $rows = $this->posting->post($note, Carbon::parse($note->note_date), $lines, $userId);

            if ($credit && $note->original_invoice_id) {
                $inv = VendorInvoice::query()->find($note->original_invoice_id);
                if ($inv) {
                    $inv->update(['amount_paid' => round((float) $inv->amount_paid + (float) $note->total_amount, 2)]);
                }
            }

            $note->update([
                'status' => VendorInvoiceStatus::Posted,
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
    private function vatByAccount(VendorNote $note): array
    {
        $taxAccounts = TaxCode::query()->whereNotNull('input_account_id')->pluck('input_account_id', 'id');
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
