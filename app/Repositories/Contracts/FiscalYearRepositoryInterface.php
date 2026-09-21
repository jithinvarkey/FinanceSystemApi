<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\FiscalPeriod;

interface FiscalYearRepositoryInterface extends BaseRepositoryInterface
{
    public function overlaps(\DateTimeInterface $start, \DateTimeInterface $end, ?int $exceptId = null): bool;

    public function findOpenPeriodForDate(\DateTimeInterface $date): ?FiscalPeriod;
}
