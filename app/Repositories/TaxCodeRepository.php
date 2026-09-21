<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TaxCode;
use App\Repositories\Contracts\TaxCodeRepositoryInterface;

final class TaxCodeRepository extends BaseRepository implements TaxCodeRepositoryInterface
{
    public function __construct(TaxCode $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'tax_type'];
    }

    protected function searchable(): array
    {
        return ['code', 'name'];
    }
}
