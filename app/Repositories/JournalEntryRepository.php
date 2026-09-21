<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;

/**
 * Eloquent repository for journal entries.
 */
final class JournalEntryRepository extends BaseRepository implements JournalEntryRepositoryInterface
{
    public function __construct(JournalEntry $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'journal_date', 'fiscal_period_id', 'created_by'];
    }

    protected function searchable(): array
    {
        return ['journal_number', 'reference', 'description'];
    }

    public function nextJournalNumber(int $year): string
    {
        $latest = $this->model->newQuery()
            ->where('journal_number', 'like', "JV-{$year}-%")
            ->lockForUpdate()
            ->orderByDesc('journal_number')
            ->value('journal_number');

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, -6)) + 1;

        return sprintf('JV-%d-%06d', $year, $sequence);
    }
}
