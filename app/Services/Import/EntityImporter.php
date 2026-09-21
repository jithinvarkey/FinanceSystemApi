<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One importable entity type (customers, open AR, historical policies, …).
 * The framework parses the uploaded sheet into header-keyed rows, asks the
 * importer to validate each row (dry-run), then commits the file atomically.
 */
interface EntityImporter
{
    /** Stable url-safe key, e.g. 'customers'. */
    public function key(): string;

    /** Human label, e.g. 'Customers'. */
    public function label(): string;

    /** Section for grouping in the UI: 'Master data' | 'Opening balances' | 'History'. */
    public function group(): string;

    /** Import order (lower first) — enforces dependencies (customers before open AR, etc.). */
    public function order(): int;

    /**
     * Template columns in order.
     *
     * @return list<array{name: string, required: bool, hint?: string}>
     */
    public function columns(): array;

    /**
     * Validate one row. Return any human-readable errors plus the normalised
     * data the commit step will consume.
     *
     * @param array<string, mixed> $row header => value
     * @return array{errors: list<string>, data: array<string, mixed>}
     */
    public function validateRow(array $row, int $rowNumber): array;

    /**
     * Persist one already-validated row. Return a short reference (the created
     * code / number) for the result report.
     *
     * @param array<string, mixed> $data the normalised data from validateRow()
     */
    public function commitRow(array $data, int $userId): string;
}
