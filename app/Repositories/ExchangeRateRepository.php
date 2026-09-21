<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ExchangeRate;
use App\Repositories\Contracts\ExchangeRateRepositoryInterface;

final class ExchangeRateRepository extends BaseRepository implements ExchangeRateRepositoryInterface
{
    public function __construct(ExchangeRate $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['currency_id'];
    }

    protected function searchable(): array
    {
        return [];
    }
}
