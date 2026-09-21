<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PremiumCollection;
use App\Repositories\Contracts\PremiumCollectionRepositoryInterface;

final class PremiumCollectionRepository extends BaseRepository implements PremiumCollectionRepositoryInterface
{
    public function __construct(PremiumCollection $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'policy_id'];
    }

    protected function searchable(): array
    {
        return ['collection_number', 'reference'];
    }
}
