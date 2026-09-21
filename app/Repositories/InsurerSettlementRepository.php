<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\InsurerSettlement;
use App\Repositories\Contracts\InsurerSettlementRepositoryInterface;

final class InsurerSettlementRepository extends BaseRepository implements InsurerSettlementRepositoryInterface
{
    public function __construct(InsurerSettlement $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'insurer_id'];
    }

    protected function searchable(): array
    {
        return ['settlement_number', 'reference'];
    }
}
