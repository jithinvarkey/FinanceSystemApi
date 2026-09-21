<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Models\LineOfBusiness;
use App\Services\Import\BaseImporter;

/**
 * Lines of business (classes of insurance). Upserts by code, so re-running a
 * sheet updates rather than duplicates.
 */
final class LineOfBusinessImporter extends BaseImporter
{
    public function key(): string
    {
        return 'lines-of-business';
    }

    public function label(): string
    {
        return 'Lines of business';
    }

    public function order(): int
    {
        return 10;
    }

    public function columns(): array
    {
        return [
            ['name' => 'code', 'required' => true, 'hint' => 'Unique LOB code, e.g. MOTOR'],
            ['name' => 'name', 'required' => true],
            ['name' => 'is_life', 'required' => false, 'hint' => 'yes / no (life class is VAT-exempt)'],
            ['name' => 'status', 'required' => false, 'hint' => 'active (default) / inactive'],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $code = $this->str($row, 'code');
        $name = $this->str($row, 'name');

        if (! $code) {
            $errors[] = 'code is required';
        }
        if (! $name) {
            $errors[] = 'name is required';
        }

        return [
            'errors' => $errors,
            'data' => [
                'code' => $code,
                'name' => $name,
                'is_life' => $this->bool($row, 'is_life'),
                'status' => $this->str($row, 'status') ?? 'active',
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        LineOfBusiness::query()->updateOrCreate(
            ['code' => $data['code']],
            ['name' => $data['name'], 'is_life' => $data['is_life'], 'status' => $data['status']],
        );

        return (string) $data['code'];
    }

    private function bool(array $row, string $key): bool
    {
        $v = mb_strtolower((string) ($this->str($row, $key) ?? ''));

        return in_array($v, ['1', 'yes', 'y', 'true', 'life'], true);
    }
}
