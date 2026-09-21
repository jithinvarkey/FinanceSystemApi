<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\VendorType;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\LineOfBusiness;
use App\Models\Product;
use App\Models\TaxCode;
use App\Models\Vendor;

/**
 * Code → model lookups shared by importers (parties/accounts are referenced by
 * their business codes in the spreadsheets, never by database id).
 */
trait ResolvesImportReferences
{
    protected function findAccount(?string $code): ?ChartOfAccount
    {
        return $code ? ChartOfAccount::query()->where('code', $code)->first() : null;
    }

    protected function findLob(?string $code): ?LineOfBusiness
    {
        return $code ? LineOfBusiness::query()->where('code', $code)->first() : null;
    }

    protected function findTaxCode(?string $code): ?TaxCode
    {
        return $code ? TaxCode::query()->where('code', $code)->first() : null;
    }

    protected function findCustomer(?string $code): ?Customer
    {
        return $code ? Customer::query()->where('customer_code', $code)->first() : null;
    }

    protected function findProduct(?string $code): ?Product
    {
        return $code ? Product::query()->where('code', $code)->first() : null;
    }

    protected function findVendor(?string $code, ?VendorType $type = null): ?Vendor
    {
        if (! $code) {
            return null;
        }
        $q = Vendor::query()->where('vendor_code', $code);
        if ($type) {
            $q->where('vendor_type', $type->value);
        }

        return $q->first();
    }
}
