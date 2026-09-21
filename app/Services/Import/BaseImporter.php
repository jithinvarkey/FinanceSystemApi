<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Carbon;

/**
 * Shared parsing/validation helpers for importers. Subclasses focus on their
 * columns + business rules.
 */
abstract class BaseImporter implements EntityImporter
{
    public function group(): string
    {
        return 'Master data';
    }

    public function order(): int
    {
        return 100;
    }

    /** Trimmed string cell, or null when blank. */
    protected function str(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** Parse a numeric cell (strips thousands separators); null when blank. */
    protected function num(array $row, string $key): ?float
    {
        $v = $this->str($row, $key);
        if ($v === null) {
            return null;
        }
        $v = str_replace([',', ' '], '', $v);

        return is_numeric($v) ? (float) $v : null;
    }

    /** Parse a date cell to Y-m-d; null when blank/unparseable. */
    protected function date(array $row, string $key): ?string
    {
        $v = $this->str($row, $key);
        if ($v === null) {
            return null;
        }

        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
