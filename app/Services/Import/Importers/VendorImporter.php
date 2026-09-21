<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Enums\VendorType;
use App\Models\Vendor;
use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;

/**
 * Vendors (suppliers and insurers). Upserts by vendor_code. Insurers carry
 * vendor_type = insurer so policies can reference them.
 */
final class VendorImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function key(): string
    {
        return 'vendors';
    }

    public function label(): string
    {
        return 'Vendors & insurers';
    }

    public function order(): int
    {
        return 30;
    }

    public function columns(): array
    {
        return [
            ['name' => 'vendor_code', 'required' => true, 'hint' => 'Unique vendor code'],
            ['name' => 'name', 'required' => true],
            ['name' => 'vendor_type', 'required' => true, 'hint' => 'supplier / service_provider / insurer / contractor / other'],
            ['name' => 'trn', 'required' => false, 'hint' => '15-digit Saudi VAT number'],
            ['name' => 'email', 'required' => false],
            ['name' => 'phone', 'required' => false],
            ['name' => 'payment_terms_days', 'required' => false, 'hint' => 'e.g. 30'],
            ['name' => 'currency_code', 'required' => false, 'hint' => 'SAR (default)'],
            ['name' => 'payable_account_code', 'required' => false, 'hint' => 'GL code of the AP control account'],
            ['name' => 'status', 'required' => false, 'hint' => 'active (default) / inactive'],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $code = $this->str($row, 'vendor_code');
        $name = $this->str($row, 'name');
        $typeRaw = mb_strtolower((string) ($this->str($row, 'vendor_type') ?? ''));
        $type = VendorType::tryFrom($typeRaw);
        $apCode = $this->str($row, 'payable_account_code');
        $ap = $this->findAccount($apCode);

        if (! $code) {
            $errors[] = 'vendor_code is required';
        }
        if (! $name) {
            $errors[] = 'name is required';
        }
        if (! $type) {
            $errors[] = "vendor_type must be one of: supplier, service_provider, insurer, contractor, other (got '{$typeRaw}')";
        }
        if ($apCode && ! $ap) {
            $errors[] = "payable_account_code '{$apCode}' not found in the chart of accounts";
        }

        return [
            'errors' => $errors,
            'data' => [
                'vendor_code' => $code,
                'name' => $name,
                'vendor_type' => $type?->value,
                'trn' => $this->str($row, 'trn'),
                'email' => $this->str($row, 'email'),
                'phone' => $this->str($row, 'phone'),
                'payment_terms_days' => (int) ($this->num($row, 'payment_terms_days') ?? 0),
                'currency_code' => $this->str($row, 'currency_code') ?? 'SAR',
                'default_payable_account_id' => $ap?->id,
                'status' => $this->str($row, 'status') ?? 'active',
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $code = $data['vendor_code'];
        unset($data['vendor_code']);
        Vendor::query()->updateOrCreate(['vendor_code' => $code], [...$data, 'created_by' => $userId]);

        return (string) $code;
    }
}
