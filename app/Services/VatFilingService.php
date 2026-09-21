<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VatReturnStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\TaxCode;
use App\Models\VatReturn;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F15 — VAT filing workflow. Turns the live VAT return (P9) into a managed
 * filing: snapshot a draft → file it (locks the period) → settle the net with
 * ZATCA as a GL entry that clears the VAT control accounts.
 */
final class VatFilingService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly VatReturnService $returns,
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    /**
     * Snapshot the computed return for a period as a DRAFT filing. Guards against
     * overlapping an already filed/paid period.
     */
    public function createDraft(Carbon $from, Carbon $to, int $userId, ?string $notes = null): VatReturn
    {
        if ($from->gt($to)) {
            throw new FinanceRuleException('The period start must be on or before the period end.');
        }
        $this->assertNoOverlap($from, $to);

        $computed = $this->returns->vatReturn($from, $to);

        return VatReturn::query()->create([
            'reference' => $this->numbers->next('vat_return', $to),
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'output_vat' => $computed['output_vat_total'],
            'input_vat' => $computed['input_vat_total'],
            'reverse_charge_base' => $computed['reverse_charge_base'],
            'reverse_charge_vat' => $computed['reverse_charge_vat'],
            'net_vat_payable' => $computed['net_vat_payable'],
            'status' => VatReturnStatus::Draft,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    /**
     * Re-snapshot a draft from the live ledger (figures may have moved since the
     * draft was created).
     */
    public function refreshDraft(VatReturn $return): VatReturn
    {
        $this->assertDraft($return);

        $computed = $this->returns->vatReturn(
            Carbon::parse((string) $return->period_from),
            Carbon::parse((string) $return->period_to),
        );

        $return->update([
            'output_vat' => $computed['output_vat_total'],
            'input_vat' => $computed['input_vat_total'],
            'reverse_charge_base' => $computed['reverse_charge_base'],
            'reverse_charge_vat' => $computed['reverse_charge_vat'],
            'net_vat_payable' => $computed['net_vat_payable'],
        ]);

        return $return->fresh();
    }

    /** File the draft with ZATCA: locks the period against further postings. */
    public function file(VatReturn $return, int $userId, ?string $zatcaReference = null): VatReturn
    {
        $this->assertDraft($return);

        $return->update([
            'status' => VatReturnStatus::Filed,
            'zatca_reference' => $zatcaReference,
            'filed_at' => now(),
            'filed_by' => $userId,
        ]);

        return $return->fresh();
    }

    /** Discard a draft (filed/paid returns cannot be deleted). */
    public function deleteDraft(VatReturn $return): void
    {
        $this->assertDraft($return);
        $return->delete();
    }

    /**
     * Settle the filed return with ZATCA. Clears both VAT control accounts and
     * moves the net cash:
     *   Dr  Output VAT payable   (output_vat)
     *   Cr  Input VAT receivable (input_vat)
     *   Cr  Bank                 (net payable)   — or Dr Bank on a refund.
     */
    public function recordPayment(VatReturn $return, int $bankAccountId, int $userId, ?Carbon $paidOn = null): VatReturn
    {
        if ($return->status !== VatReturnStatus::Filed) {
            throw new FinanceRuleException('Only a filed VAT return can be settled.');
        }

        $outputAccountId = $this->resolvePostable($this->vatControlAccountId('output_account_id'));
        $inputAccountId = $this->resolvePostable($this->vatControlAccountId('input_account_id', recoverableOnly: true));
        $bankId = $this->resolvePostable($bankAccountId);

        $output = round((float) $return->output_vat, 2);
        $input = round((float) $return->input_vat, 2);
        $net = round((float) $return->net_vat_payable, 2);
        $paidOn = $paidOn ?? Carbon::today();

        // A nil return has nothing to settle — close it without a GL entry.
        if ($output === 0.0 && $input === 0.0 && $net === 0.0) {
            $return->update(['status' => VatReturnStatus::Paid, 'paid_at' => now(), 'paid_by' => $userId]);

            return $return->fresh();
        }

        return DB::transaction(function () use ($return, $outputAccountId, $inputAccountId, $bankId, $output, $input, $net, $paidOn, $userId): VatReturn {
            $lines = [];

            if ($output > 0) {
                $lines[] = new PostingLine($outputAccountId, $output, 0.0, 'SAR', 1.0, null, 'VAT settlement — output VAT '.$return->reference);
            }
            if ($input > 0) {
                $lines[] = new PostingLine($inputAccountId, 0.0, $input, 'SAR', 1.0, null, 'VAT settlement — input VAT '.$return->reference);
            }
            // Net cash leg: pay (credit bank) when payable, receive (debit bank) on a refund.
            if ($net >= 0) {
                $lines[] = new PostingLine($bankId, 0.0, $net, 'SAR', 1.0, null, 'VAT paid to ZATCA — '.$return->reference);
            } else {
                $lines[] = new PostingLine($bankId, abs($net), 0.0, 'SAR', 1.0, null, 'VAT refund from ZATCA — '.$return->reference);
            }

            $rows = $this->posting->post($return, $paidOn, $lines, $userId);

            $return->update([
                'status' => VatReturnStatus::Paid,
                'paid_at' => now(),
                'paid_by' => $userId,
                'payment_batch_number' => $rows->first()->batch_number,
            ]);

            return $return->fresh();
        });
    }

    /**
     * True when a filed/paid VAT return locks the given posting date. Used by the
     * GL gateway to keep filed figures tied out.
     */
    public function periodIsLocked(Carbon $date): bool
    {
        return VatReturn::query()
            ->whereIn('status', [VatReturnStatus::Filed->value, VatReturnStatus::Paid->value])
            ->whereDate('period_from', '<=', $date->toDateString())
            ->whereDate('period_to', '>=', $date->toDateString())
            ->exists();
    }

    // ----- internals -----

    private function assertDraft(VatReturn $return): void
    {
        if ($return->status !== VatReturnStatus::Draft) {
            throw new FinanceRuleException('This VAT return is already filed and can no longer be edited.');
        }
    }

    private function assertNoOverlap(Carbon $from, Carbon $to): void
    {
        $overlap = VatReturn::query()
            ->whereIn('status', [VatReturnStatus::Filed->value, VatReturnStatus::Paid->value])
            ->whereDate('period_from', '<=', $to->toDateString())
            ->whereDate('period_to', '>=', $from->toDateString())
            ->exists();

        if ($overlap) {
            throw new FinanceRuleException('A filed VAT return already covers part of this period.');
        }
    }

    private function vatControlAccountId(string $column, bool $recoverableOnly = false): int
    {
        $id = TaxCode::query()
            ->when($recoverableOnly, fn ($q) => $q->where('is_recoverable', true))
            ->whereNotNull($column)
            ->value($column);

        if ($id === null) {
            throw new FinanceRuleException('No VAT control account is configured for the tax codes.');
        }

        return (int) $id;
    }
}
