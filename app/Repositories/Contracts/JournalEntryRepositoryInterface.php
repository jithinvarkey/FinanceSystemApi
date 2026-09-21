<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

interface JournalEntryRepositoryInterface extends BaseRepositoryInterface
{
    /** Next sequential journal number, e.g. JV-2026-000014. */
    public function nextJournalNumber(int $year): string;
}
