<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Exceptions\FinanceRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Reads an uploaded .xlsx into header-keyed rows, runs an importer's dry-run
 * validation, and commits the whole file atomically (all-or-nothing — a single
 * failed row rolls the file back, so a migration never lands half-applied).
 */
final class ExcelImportService
{
    /**
     * Parse the sheet into rows keyed by the importer's column names (header
     * matching is case/space/underscore-insensitive).
     *
     * @return list<array{row: int, data: array<string, mixed>}>
     */
    public function parse(UploadedFile $file, EntityImporter $importer): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);
        if (count($matrix) === 0) {
            return [];
        }

        $headers = array_map(fn ($h): string => $this->norm((string) $h), array_values($matrix[0]));
        $columns = $importer->columns();
        $rows = [];

        foreach (array_slice($matrix, 1) as $i => $cells) {
            $cells = array_values($cells);
            if ($this->isEmptyRow($cells)) {
                continue;
            }

            $data = [];
            foreach ($columns as $col) {
                $idx = array_search($this->norm($col['name']), $headers, true);
                $data[$col['name']] = $idx === false ? null : ($cells[$idx] ?? null);
            }
            $rows[] = ['row' => $i + 2, 'data' => $data]; // +2: 1-based + header row
        }

        return $rows;
    }

    /**
     * Validate every row without writing anything.
     *
     * @param list<array{row: int, data: array<string, mixed>}> $rows
     * @return array{total: int, valid: int, invalid: int, rows: list<array<string, mixed>>}
     */
    public function dryRun(EntityImporter $importer, array $rows): array
    {
        $out = [];
        $valid = 0;
        foreach ($rows as $r) {
            $result = $importer->validateRow($r['data'], $r['row']);
            $ok = count($result['errors']) === 0;
            $valid += $ok ? 1 : 0;
            $out[] = [
                'row' => $r['row'],
                'status' => $ok ? 'ok' : 'error',
                'errors' => $result['errors'],
                'values' => array_values($r['data']),
            ];
        }

        return ['total' => count($rows), 'valid' => $valid, 'invalid' => count($rows) - $valid, 'rows' => $out];
    }

    /**
     * Commit the whole file in one transaction. Aborts (and rolls back) if any
     * row fails validation, reporting which rows blocked it.
     *
     * @param list<array{row: int, data: array<string, mixed>}> $rows
     * @return array{committed: int, refs: list<array{row: int, ref: string}>}
     */
    public function commit(EntityImporter $importer, array $rows, int $userId): array
    {
        $errors = [];
        $validated = [];
        foreach ($rows as $r) {
            $result = $importer->validateRow($r['data'], $r['row']);
            if (count($result['errors']) > 0) {
                $errors[] = "Row {$r['row']}: ".implode('; ', $result['errors']);
            } else {
                $validated[] = ['row' => $r['row'], 'data' => $result['data']];
            }
        }

        if (count($errors) > 0) {
            throw new FinanceRuleException('Import aborted — fix these rows and retry: '.implode(' | ', array_slice($errors, 0, 10)).(count($errors) > 10 ? ' …' : ''));
        }

        return DB::transaction(function () use ($importer, $validated, $userId): array {
            $refs = [];
            foreach ($validated as $v) {
                $refs[] = ['row' => $v['row'], 'ref' => $importer->commitRow($v['data'], $userId)];
            }

            return ['committed' => count($refs), 'refs' => $refs];
        });
    }

    /** Build a header-only .xlsx template for the importer. */
    public function template(EntityImporter $importer): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($importer->label(), 0, 31));

        $colIndex = 1;
        foreach ($importer->columns() as $col) {
            $letter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($letter.'1', $col['name'].($col['required'] ? ' *' : ''));
            $sheet->getColumnDimension($letter)->setWidth(max(14, strlen($col['name']) + 4));
            if (isset($col['hint'])) {
                $sheet->getComment($letter.'1')->getText()->createText($col['hint']);
            }
            $colIndex++;
        }
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        $writer = new XlsxWriter($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function norm(string $s): string
    {
        return preg_replace('/[\s_*]+/', '', mb_strtolower(trim($s))) ?? '';
    }

    /** @param array<int, mixed> $cells */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }
}
