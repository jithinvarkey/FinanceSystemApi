<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Policy;
use App\Repositories\Contracts\PolicyRepositoryInterface;

final class PolicyRepository extends BaseRepository implements PolicyRepositoryInterface
{
    public function __construct(Policy $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'customer_id', 'insurer_id', 'product_id'];
    }

    protected function searchable(): array
    {
        return ['policy_number', 'insurer_policy_no', 'quote_number'];
    }
}
