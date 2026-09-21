<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CustomerInvoice;
use App\Repositories\Contracts\CustomerInvoiceRepositoryInterface;

final class CustomerInvoiceRepository extends BaseRepository implements CustomerInvoiceRepositoryInterface
{
    public function __construct(CustomerInvoice $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'customer_id'];
    }

    protected function searchable(): array
    {
        return ['invoice_number', 'reference'];
    }
}
