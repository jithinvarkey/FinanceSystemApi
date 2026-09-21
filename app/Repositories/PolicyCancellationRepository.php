<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PolicyCancellation;
use App\Repositories\Contracts\PolicyCancellationRepositoryInterface;

final class PolicyCancellationRepository extends BaseRepository implements PolicyCancellationRepositoryInterface
{
    public function __construct(PolicyCancellation $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'policy_id', 'method'];
    }

    protected function searchable(): array
    {
        return ['cancellation_number', 'reason'];
    }
}
