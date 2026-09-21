<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIN-0036..0038 — Manual journal lifecycle.
 *
 * Workflow: draft -> submitted -> approved -> posted, with rejected and
 * reversed branches. Posting delegates to GlPostingService so every ledger
 * guarantee (balance, open period, postable accounts) applies uniformly.
 *
 * Segregation of duties: the creator may neither approve nor post their
 * own journal (FIN-0057 control, enforced here at the document level).
 */
final class JournalService
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $journals,
        private readonly GlPostingService $posting,
    ) {
    }

    /**
     * Create a draft journal with its lines.
     *
     * @param array{journal_date: string, description: string, reference?: ?string, currency_code?: string, lines: list<array<string, mixed>>} $data
     */
    public function createDraft(array $data, int $userId): JournalEntry
    {
        $lines = $this->normaliseLines($data['lines'] ?? []);

        return DB::transaction(function () use ($data, $lines, $userId): JournalEntry {
            $journal = $this->journals->create([
                'journal_number' => $this->journals->nextJournalNumber(Carbon::parse($data['journal_date'])->year),
                'journal_date' => $data['journal_date'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'],
                'status' => JournalStatus::Draft,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'total_debit' => $this->sum($lines, 'debit'),
                'total_credit' => $this->sum($lines, 'credit'),
                'created_by' => $userId,
            ]);

            $journal->lines()->createMany($lines);

            return $journal->load('lines.account');
        });
    }

    /**
     * Replace lines and header of an editable (draft/rejected) journal.
     */
    public function updateDraft(JournalEntry $journal, array $data, int $userId): JournalEntry
    {
        if (! $journal->status->isEditable()) {
            throw FinanceRuleException::notEditable($journal->status->value);
        }

        $lines = $this->normaliseLines($data['lines'] ?? []);

        return DB::transaction(function () use ($journal, $data, $lines): JournalEntry {
            $journal->update([
                'journal_date' => $data['journal_date'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'],
                'currency_code' => $data['currency_code'] ?? $journal->currency_code,
                'total_debit' => $this->sum($lines, 'debit'),
                'total_credit' => $this->sum($lines, 'credit'),
                'status' => JournalStatus::Draft, // rejected journal returns to draft on edit
                'rejection_reason' => null,
            ]);

            $journal->lines()->delete();
            $journal->lines()->createMany($lines);

            return $journal->load('lines.account');
        });
    }

    /** Delete a journal that never left draft. */
    public function deleteDraft(JournalEntry $journal): void
    {
        if (! $journal->status->isEditable()) {
            throw FinanceRuleException::notEditable($journal->status->value);
        }

        $journal->delete();
    }

    /** draft|rejected -> submitted. Validates balance before entering the queue. */
    public function submit(JournalEntry $journal, int $userId): JournalEntry
    {
        $this->assertTransition($journal, JournalStatus::Submitted);
        $this->assertBalancedDocument($journal);

        $journal->update([
            'status' => JournalStatus::Submitted,
            'submitted_by' => $userId,
            'submitted_at' => now(),
            'rejection_reason' => null,
        ]);

        return $journal;
    }

    /** submitted -> approved. SoD: approver must differ from creator. */
    public function approve(JournalEntry $journal, int $userId): JournalEntry
    {
        $this->assertTransition($journal, JournalStatus::Approved);

        if ($journal->created_by === $userId) {
            throw FinanceRuleException::sodViolation('approve');
        }

        $journal->update([
            'status' => JournalStatus::Approved,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        return $journal;
    }

    /** submitted -> rejected, with a mandatory reason for the audit trail. */
    public function reject(JournalEntry $journal, int $userId, string $reason): JournalEntry
    {
        $this->assertTransition($journal, JournalStatus::Rejected);

        $journal->update([
            'status' => JournalStatus::Rejected,
            'approved_by' => $userId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $journal;
    }

    /**
     * approved -> posted. Writes the ledger batch through GlPostingService;
     * the journal and its GL rows commit or roll back together.
     */
    public function post(JournalEntry $journal, int $userId): JournalEntry
    {
        $this->assertTransition($journal, JournalStatus::Posted);

        if ($journal->created_by === $userId) {
            throw FinanceRuleException::sodViolation('post');
        }

        return DB::transaction(function () use ($journal, $userId): JournalEntry {
            $lines = $journal->lines->map(
                fn ($l) => new PostingLine(
                    accountId: $l->account_id,
                    debit: (float) $l->debit,
                    credit: (float) $l->credit,
                    currencyCode: $l->currency_code,
                    exchangeRate: (float) $l->exchange_rate,
                    costCenterId: $l->cost_center_id,
                    description: $l->description ?? $journal->description,
                    dimensionId: $l->dimension_id,
                ),
            )->all();

            $rows = $this->posting->post($journal, $journal->journal_date, $lines, $userId);

            $journal->update([
                'status' => JournalStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $journal;
        });
    }

    /**
     * posted -> reversed. Creates a mirrored, already-posted reversal journal
     * dated $reversalDate and reverses the original GL batch.
     */
    public function reverse(JournalEntry $journal, int $userId, Carbon $reversalDate): JournalEntry
    {
        $this->assertTransition($journal, JournalStatus::Reversed);

        return DB::transaction(function () use ($journal, $userId, $reversalDate): JournalEntry {
            $reversal = $this->journals->create([
                'journal_number' => $this->journals->nextJournalNumber($reversalDate->year),
                'journal_date' => $reversalDate->toDateString(),
                'reference' => $journal->journal_number,
                'description' => "Reversal of {$journal->journal_number}",
                'status' => JournalStatus::Posted,
                'currency_code' => $journal->currency_code,
                'total_debit' => $journal->total_credit,
                'total_credit' => $journal->total_debit,
                'reversal_of_id' => $journal->id,
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            $reversal->lines()->createMany(
                $journal->lines->map(fn ($l) => [
                    'line_no' => $l->line_no,
                    'account_id' => $l->account_id,
                    'cost_center_id' => $l->cost_center_id,
                    'dimension_id' => $l->dimension_id,
                    'description' => "Reversal: {$l->description}",
                    'debit' => $l->credit,
                    'credit' => $l->debit,
                    'currency_code' => $l->currency_code,
                    'exchange_rate' => $l->exchange_rate,
                ])->all(),
            );

            $rows = $this->posting->reverse($journal, $reversalDate, $userId);
            $reversal->update(['fiscal_period_id' => $rows->first()->fiscal_period_id]);

            $journal->update(['status' => JournalStatus::Reversed]);

            return $reversal->load('lines.account');
        });
    }

    // ----- internals -----

    /** @throws FinanceRuleException */
    private function assertTransition(JournalEntry $journal, JournalStatus $to): void
    {
        if (! in_array($to, $journal->status->allowedTransitions(), true)) {
            throw FinanceRuleException::invalidTransition($journal->status->value, $to->value);
        }
    }

    /** @throws FinanceRuleException */
    private function assertBalancedDocument(JournalEntry $journal): void
    {
        $debit = (int) round($journal->lines->sum(fn ($l) => (float) $l->debit * (float) $l->exchange_rate) * 100);
        $credit = (int) round($journal->lines->sum(fn ($l) => (float) $l->credit * (float) $l->exchange_rate) * 100);

        if ($journal->lines->count() < 2) {
            throw FinanceRuleException::emptyJournal();
        }

        if ($debit !== $credit) {
            throw FinanceRuleException::unbalanced(
                number_format($debit / 100, 2, '.', ''),
                number_format($credit / 100, 2, '.', ''),
            );
        }
    }

    /**
     * Validate + sequence raw line payloads.
     *
     * @return list<array<string, mixed>>
     * @throws FinanceRuleException
     */
    private function normaliseLines(array $raw): array
    {
        if (count($raw) < 2) {
            throw FinanceRuleException::emptyJournal();
        }

        $lines = [];
        foreach (array_values($raw) as $i => $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit > 0 && $credit > 0) {
                throw FinanceRuleException::mixedLine($i + 1);
            }

            $lines[] = [
                'line_no' => $i + 1,
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => isset($line['cost_center_id']) ? (int) $line['cost_center_id'] : null,
                'dimension_id' => isset($line['dimension_id']) ? (int) $line['dimension_id'] : null,
                'description' => $line['description'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'currency_code' => $line['currency_code'] ?? 'SAR',
                'exchange_rate' => (float) ($line['exchange_rate'] ?? 1),
            ];
        }

        return $lines;
    }

    private function sum(array $lines, string $key): float
    {
        return round(array_sum(array_column($lines, $key)), 2);
    }
}
