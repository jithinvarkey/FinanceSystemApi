<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CostCenter;
use App\Repositories\Contracts\CostCenterRepositoryInterface;

final class CostCenterRepository extends BaseRepository implements CostCenterRepositoryInterface
{
    public function __construct(CostCenter $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'parent_id'];
    }

    protected function searchable(): array
    {
        return ['code', 'name'];
    }
}
