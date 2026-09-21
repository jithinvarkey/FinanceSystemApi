<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface RecurringJournalRepositoryInterface extends BaseRepositoryInterface
{
    /** Next sequential template code, e.g. RJ-000003. */
    public function nextTemplateCode(): string;

    /** Active templates whose next_run_date is on or before $asOf. */
    public function due(\DateTimeInterface $asOf): Collection;
}
