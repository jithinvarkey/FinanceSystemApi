<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\RecurringJournal;
use App\Repositories\Contracts\RecurringJournalRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent repository for recurring journal templates.
 */
final class RecurringJournalRepository extends BaseRepository implements RecurringJournalRepositoryInterface
{
    public function __construct(RecurringJournal $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'frequency'];
    }

    protected function searchable(): array
    {
        return ['template_code', 'name'];
    }

    public function nextTemplateCode(): string
    {
        $latest = $this->model->newQuery()
            ->lockForUpdate()
            ->orderByDesc('template_code')
            ->value('template_code');

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, -6)) + 1;

        return sprintf('RJ-%06d', $sequence);
    }

    public function due(\DateTimeInterface $asOf): Collection
    {
        return $this->model->newQuery()
            ->with('lines')
            ->where('status', 'active')
            ->whereDate('next_run_date', '<=', $asOf)
            ->get();
    }
}
