<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecurringFrequency;
use App\Exceptions\FinanceRuleException;
use App\Models\RecurringJournal;
use App\Repositories\Contracts\RecurringJournalRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FIN-0039 — Recurring journal templates and the generation run.
 *
 * Each due occurrence materialises as a DRAFT journal entry attributed to
 * the template, so the standard submit/approve/post workflow still applies —
 * recurrence never bypasses approval controls.
 */
final class RecurringJournalService
{
    public function __construct(
        private readonly RecurringJournalRepositoryInterface $templates,
        private readonly JournalService $journals,
    ) {
    }

    /**
     * Create a template with balanced lines.
     *
     * @param array{name: string, frequency: string, day_of_month: int, start_date: string, end_date?: ?string, description?: ?string, currency_code?: string, lines: list<array<string, mixed>>} $data
     */
    public function create(array $data, int $userId): RecurringJournal
    {
        $this->assertBalancedTemplate($data['lines'] ?? []);

        $frequency = RecurringFrequency::from($data['frequency']);
        $start = Carbon::parse($data['start_date']);
        $firstRun = $start->copy()->setDay(min((int) $data['day_of_month'], 28));
        if ($firstRun->lessThan($start)) {
            $firstRun = $frequency->nextAfter($firstRun, (int) $data['day_of_month']);
        }

        return DB::transaction(function () use ($data, $userId, $firstRun): RecurringJournal {
            $template = $this->templates->create([
                'template_code' => $this->templates->nextTemplateCode(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'frequency' => $data['frequency'],
                'day_of_month' => $data['day_of_month'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'next_run_date' => $firstRun->toDateString(),
                'status' => 'active',
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'created_by' => $userId,
            ]);

            $template->lines()->createMany(
                collect($data['lines'])->values()->map(fn (array $l, int $i) => [
                    'line_no' => $i + 1,
                    'account_id' => (int) $l['account_id'],
                    'cost_center_id' => isset($l['cost_center_id']) ? (int) $l['cost_center_id'] : null,
                    'description' => $l['description'] ?? null,
                    'debit' => round((float) ($l['debit'] ?? 0), 2),
                    'credit' => round((float) ($l['credit'] ?? 0), 2),
                ])->all(),
            );

            return $template->load('lines.account');
        });
    }

    /** Pause an active template (skips generation until resumed). */
    public function pause(RecurringJournal $template): RecurringJournal
    {
        $template->update(['status' => 'paused']);

        return $template;
    }

    /** Resume a paused template. */
    public function resume(RecurringJournal $template): RecurringJournal
    {
        $template->update(['status' => 'active']);

        return $template;
    }

    /**
     * Generate draft journals for every template due on or before $asOf.
     * Called by the scheduler (daily) or manually from the console screen.
     *
     * @return Collection<int, \App\Models\JournalEntry> The journals created
     */
    public function generateDue(Carbon $asOf, int $userId): Collection
    {
        $generated = collect();

        foreach ($this->templates->due($asOf) as $template) {
            $generated->push($this->materialise($template, $userId));
        }

        return $generated;
    }

    // ----- internals -----

    private function materialise(RecurringJournal $template, int $userId): \App\Models\JournalEntry
    {
        return DB::transaction(function () use ($template, $userId) {
            $runDate = $template->next_run_date;

            $journal = $this->journals->createDraft([
                'journal_date' => $runDate->toDateString(),
                'reference' => $template->template_code,
                'description' => "{$template->name} ({$runDate->format('M Y')})",
                'currency_code' => $template->currency_code,
                'lines' => $template->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'cost_center_id' => $l->cost_center_id,
                    'description' => $l->description,
                    'debit' => (float) $l->debit,
                    'credit' => (float) $l->credit,
                    'currency_code' => $template->currency_code,
                ])->all(),
            ], $userId);

            $journal->update(['recurring_journal_id' => $template->id]);

            $next = $template->frequency->nextAfter($runDate, $template->day_of_month);
            $finished = $template->end_date !== null && $next->greaterThan($template->end_date);

            $template->update([
                'next_run_date' => $next->toDateString(),
                'status' => $finished ? 'completed' : $template->status,
            ]);

            return $journal;
        });
    }

    /** @throws FinanceRuleException */
    private function assertBalancedTemplate(array $lines): void
    {
        if (count($lines) < 2) {
            throw FinanceRuleException::emptyJournal();
        }

        $debit = (int) round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines)) * 100);
        $credit = (int) round(array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $lines)) * 100);

        if ($debit !== $credit) {
            throw FinanceRuleException::unbalanced(
                number_format($debit / 100, 2, '.', ''),
                number_format($credit / 100, 2, '.', ''),
            );
        }
    }
}
