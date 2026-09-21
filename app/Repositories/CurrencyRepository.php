<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Currency;
use App\Repositories\Contracts\CurrencyRepositoryInterface;

final class CurrencyRepository extends BaseRepository implements CurrencyRepositoryInterface
{
    public function __construct(Currency $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'is_base'];
    }

    protected function searchable(): array
    {
        return ['code', 'name'];
    }
}
