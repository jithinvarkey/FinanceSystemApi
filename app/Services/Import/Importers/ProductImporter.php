<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Models\Product;
use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;

/**
 * Products (policy products). Upserts by code; references its line of business,
 * tax code and commission revenue account by their codes.
 */
final class ProductImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function order(): int
    {
        return 40;
    }

    public function columns(): array
    {
        return [
            ['name' => 'code', 'required' => true, 'hint' => 'Unique product code'],
            ['name' => 'name', 'required' => true],
            ['name' => 'lob_code', 'required' => true, 'hint' => 'Line-of-business code (import LOBs first)'],
            ['name' => 'default_commission_rate', 'required' => false, 'hint' => 'Percent, e.g. 12.5'],
            ['name' => 'tax_code', 'required' => false, 'hint' => 'VAT15 / VAT5 / NOVAT / EXEMPT'],
            ['name' => 'commission_account_code', 'required' => false, 'hint' => 'GL code of the commission revenue account'],
            ['name' => 'status', 'required' => false, 'hint' => 'active (default) / inactive'],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $code = $this->str($row, 'code');
        $name = $this->str($row, 'name');
        $lobCode = $this->str($row, 'lob_code');
        $lob = $this->findLob($lobCode);
        $taxCode = $this->str($row, 'tax_code');
        $tax = $this->findTaxCode($taxCode);
        $commCode = $this->str($row, 'commission_account_code');
        $comm = $this->findAccount($commCode);

        if (! $code) {
            $errors[] = 'code is required';
        }
        if (! $name) {
            $errors[] = 'name is required';
        }
        if (! $lobCode) {
            $errors[] = 'lob_code is required';
        } elseif (! $lob) {
            $errors[] = "lob_code '{$lobCode}' not found (import lines of business first)";
        }
        if ($taxCode && ! $tax) {
            $errors[] = "tax_code '{$taxCode}' not found";
        }
        if ($commCode && ! $comm) {
            $errors[] = "commission_account_code '{$commCode}' not found";
        }

        return [
            'errors' => $errors,
            'data' => [
                'code' => $code,
                'name' => $name,
                'lob_id' => $lob?->id,
                'default_commission_rate' => $this->num($row, 'default_commission_rate') ?? 0,
                'default_tax_code_id' => $tax?->id,
                'commission_revenue_account_id' => $comm?->id,
                'status' => $this->str($row, 'status') ?? 'active',
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $code = $data['code'];
        unset($data['code']);
        Product::query()->updateOrCreate(['code' => $code], $data);

        return (string) $code;
    }
}
