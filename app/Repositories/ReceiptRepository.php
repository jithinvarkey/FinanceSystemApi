<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Receipt;
use App\Repositories\Contracts\ReceiptRepositoryInterface;

final class ReceiptRepository extends BaseRepository implements ReceiptRepositoryInterface
{
    public function __construct(Receipt $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'customer_id'];
    }

    protected function searchable(): array
    {
        return ['receipt_number', 'reference'];
    }
}
