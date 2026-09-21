<?php

declare(strict_types=1);

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generic .xlsx exporter for reports: a title row, a bold header row, then data
 * rows. Reused across financial statements, aging, ledgers, etc.
 */
final class SheetExporter
{
    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public function download(string $filename, string $title, array $headers, array $rows): StreamedResponse
    {
        $binary = $this->build($title, $headers, $rows);

        return response()->streamDownload(
            fn () => print($binary),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public function build(string $title, array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $lastCol = Coordinate::stringFromColumnIndex(max(1, count($headers)));

        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'3', $h);
        }
        $sheet->getStyle("A3:{$lastCol}3")->getFont()->setBold(true);

        $r = 4;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$r, $val);
            }
            $r++;
        }

        foreach (range(1, max(1, count($headers))) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        $writer = new XlsxWriter($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}
