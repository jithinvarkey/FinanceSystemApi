<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\VendorInvoice;
use App\Repositories\Contracts\VendorInvoiceRepositoryInterface;

final class VendorInvoiceRepository extends BaseRepository implements VendorInvoiceRepositoryInterface
{
    public function __construct(VendorInvoice $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'vendor_id'];
    }

    protected function searchable(): array
    {
        return ['invoice_number', 'vendor_invoice_no', 'reference'];
    }
}
