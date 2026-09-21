<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;
use App\Services\OpeningInvoiceService;

/**
 * Open payables at cutover — one open vendor invoice per row, brought in as a
 * posted open item (Dr Opening Balance Equity / Cr AP). Import vendors first.
 */
final class OpeningPayableImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function __construct(private readonly OpeningInvoiceService $service)
    {
    }

    public function key(): string
    {
        return 'opening-payables';
    }

    public function label(): string
    {
        return 'Open payables (AP)';
    }

    public function group(): string
    {
        return 'Opening balances';
    }

    public function order(): int
    {
        return 70;
    }

    public function columns(): array
    {
        return [
            ['name' => 'cutover_date', 'required' => true, 'hint' => 'GL posting date, e.g. 2026-01-01'],
            ['name' => 'vendor_code', 'required' => true],
            ['name' => 'invoice_number', 'required' => true, 'hint' => 'Original invoice number (kept as-is)'],
            ['name' => 'vendor_invoice_no', 'required' => false],
            ['name' => 'invoice_date', 'required' => true, 'hint' => 'Original invoice date (drives aging)'],
            ['name' => 'due_date', 'required' => false],
            ['name' => 'outstanding_amount', 'required' => true, 'hint' => 'Open balance, gross'],
            ['name' => 'reference', 'required' => false],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $venCode = $this->str($row, 'vendor_code');
        $vendor = $this->findVendor($venCode);
        $cutover = $this->date($row, 'cutover_date');
        $invNo = $this->str($row, 'invoice_number');
        $invDate = $this->date($row, 'invoice_date');
        $amount = $this->num($row, 'outstanding_amount');

        if (! $cutover) {
            $errors[] = 'cutover_date is required (valid date)';
        }
        if (! $venCode) {
            $errors[] = 'vendor_code is required';
        } elseif (! $vendor) {
            $errors[] = "vendor_code '{$venCode}' not found (import vendors first)";
        } elseif (! $vendor->default_payable_account_id) {
            $errors[] = "vendor '{$venCode}' has no payable account set";
        }
        if (! $invNo) {
            $errors[] = 'invoice_number is required';
        }
        if (! $invDate) {
            $errors[] = 'invoice_date is required (valid date)';
        }
        if (! $amount || $amount <= 0) {
            $errors[] = 'outstanding_amount must be a positive number';
        }

        return [
            'errors' => $errors,
            'data' => [
                'cutover_date' => $cutover,
                'vendor_id' => $vendor?->id,
                'invoice_number' => $invNo,
                'vendor_invoice_no' => $this->str($row, 'vendor_invoice_no'),
                'invoice_date' => $invDate,
                'due_date' => $this->date($row, 'due_date'),
                'outstanding_amount' => $amount,
                'reference' => $this->str($row, 'reference'),
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $this->service->postOpeningPayable($data, $userId);

        return (string) $data['invoice_number'];
    }
}
