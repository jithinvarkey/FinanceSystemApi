<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Repositories\Contracts\FiscalYearRepositoryInterface;

final class FiscalYearRepository extends BaseRepository implements FiscalYearRepositoryInterface
{
    public function __construct(FiscalYear $model)
    {
        parent::__construct($model);
    }

    public function overlaps(\DateTimeInterface $start, \DateTimeInterface $end, ?int $exceptId = null): bool
    {
        return FiscalYear::query()
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();
    }

    public function findOpenPeriodForDate(\DateTimeInterface $date): ?FiscalPeriod
    {
        return FiscalPeriod::query()->containing($date)->first();
    }
}
