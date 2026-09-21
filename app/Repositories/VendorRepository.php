<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Vendor;
use App\Repositories\Contracts\VendorRepositoryInterface;

final class VendorRepository extends BaseRepository implements VendorRepositoryInterface
{
    public function __construct(Vendor $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'vendor_type', 'is_blocked'];
    }

    protected function searchable(): array
    {
        return ['vendor_code', 'name', 'trade_name', 'trn'];
    }
}
