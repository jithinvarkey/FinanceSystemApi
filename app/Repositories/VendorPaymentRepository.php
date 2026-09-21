<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\VendorPayment;
use App\Repositories\Contracts\VendorPaymentRepositoryInterface;

final class VendorPaymentRepository extends BaseRepository implements VendorPaymentRepositoryInterface
{
    public function __construct(VendorPayment $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'vendor_id'];
    }

    protected function searchable(): array
    {
        return ['payment_number', 'reference'];
    }
}
