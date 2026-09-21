<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;

/**
 * Customers. Upserts by customer_code. The receivable control account is
 * referenced by its GL code (resolved to an id).
 */
final class CustomerImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function order(): int
    {
        return 20;
    }

    public function columns(): array
    {
        return [
            ['name' => 'customer_code', 'required' => true, 'hint' => 'Unique customer code'],
            ['name' => 'name', 'required' => true],
            ['name' => 'customer_type', 'required' => true, 'hint' => 'individual / corporate / government / broker / other'],
            ['name' => 'trn', 'required' => false, 'hint' => '15-digit Saudi VAT number'],
            ['name' => 'email', 'required' => false],
            ['name' => 'phone', 'required' => false],
            ['name' => 'payment_terms_days', 'required' => false, 'hint' => 'e.g. 30'],
            ['name' => 'currency_code', 'required' => false, 'hint' => 'SAR (default)'],
            ['name' => 'receivable_account_code', 'required' => false, 'hint' => 'GL code of the AR control account'],
            ['name' => 'status', 'required' => false, 'hint' => 'active (default) / inactive'],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $code = $this->str($row, 'customer_code');
        $name = $this->str($row, 'name');
        $typeRaw = mb_strtolower((string) ($this->str($row, 'customer_type') ?? ''));
        $type = CustomerType::tryFrom($typeRaw);
        $arCode = $this->str($row, 'receivable_account_code');
        $ar = $this->findAccount($arCode);

        if (! $code) {
            $errors[] = 'customer_code is required';
        }
        if (! $name) {
            $errors[] = 'name is required';
        }
        if (! $type) {
            $errors[] = "customer_type must be one of: individual, corporate, government, broker, other (got '{$typeRaw}')";
        }
        if ($arCode && ! $ar) {
            $errors[] = "receivable_account_code '{$arCode}' not found in the chart of accounts";
        }

        return [
            'errors' => $errors,
            'data' => [
                'customer_code' => $code,
                'name' => $name,
                'customer_type' => $type?->value,
                'trn' => $this->str($row, 'trn'),
                'email' => $this->str($row, 'email'),
                'phone' => $this->str($row, 'phone'),
                'payment_terms_days' => (int) ($this->num($row, 'payment_terms_days') ?? 0),
                'currency_code' => $this->str($row, 'currency_code') ?? 'SAR',
                'default_receivable_account_id' => $ar?->id,
                'status' => $this->str($row, 'status') ?? 'active',
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $code = $data['customer_code'];
        unset($data['customer_code']);
        Customer::query()->updateOrCreate(['customer_code' => $code], [...$data, 'created_by' => $userId]);

        return (string) $code;
    }
}
