<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ApprovalRequest;
use App\Repositories\Contracts\ApprovalRequestRepositoryInterface;

final class ApprovalRequestRepository extends BaseRepository implements ApprovalRequestRepositoryInterface
{
    public function __construct(ApprovalRequest $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'document_type'];
    }

    protected function searchable(): array
    {
        return ['document_type'];
    }
}
