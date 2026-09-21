<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;

final class CustomerRepository extends BaseRepository implements CustomerRepositoryInterface
{
    public function __construct(Customer $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'customer_type', 'is_blocked'];
    }

    protected function searchable(): array
    {
        return ['customer_code', 'name', 'trade_name', 'trn'];
    }
}
