<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;
use App\Services\OpeningInvoiceService;

/**
 * Open receivables at cutover — one open customer invoice per row, brought in as
 * a posted open item (Dr AR / Cr Opening Balance Equity) preserving its original
 * number and date. Import customers first.
 */
final class OpeningReceivableImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function __construct(private readonly OpeningInvoiceService $service)
    {
    }

    public function key(): string
    {
        return 'opening-receivables';
    }

    public function label(): string
    {
        return 'Open receivables (AR)';
    }

    public function group(): string
    {
        return 'Opening balances';
    }

    public function order(): int
    {
        return 60;
    }

    public function columns(): array
    {
        return [
            ['name' => 'cutover_date', 'required' => true, 'hint' => 'GL posting date, e.g. 2026-01-01'],
            ['name' => 'customer_code', 'required' => true],
            ['name' => 'invoice_number', 'required' => true, 'hint' => 'Original invoice number (kept as-is)'],
            ['name' => 'invoice_date', 'required' => true, 'hint' => 'Original invoice date (drives aging)'],
            ['name' => 'due_date', 'required' => false],
            ['name' => 'outstanding_amount', 'required' => true, 'hint' => 'Open balance, gross'],
            ['name' => 'reference', 'required' => false],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $custCode = $this->str($row, 'customer_code');
        $customer = $this->findCustomer($custCode);
        $cutover = $this->date($row, 'cutover_date');
        $invNo = $this->str($row, 'invoice_number');
        $invDate = $this->date($row, 'invoice_date');
        $amount = $this->num($row, 'outstanding_amount');

        if (! $cutover) {
            $errors[] = 'cutover_date is required (valid date)';
        }
        if (! $custCode) {
            $errors[] = 'customer_code is required';
        } elseif (! $customer) {
            $errors[] = "customer_code '{$custCode}' not found (import customers first)";
        } elseif (! $customer->default_receivable_account_id) {
            $errors[] = "customer '{$custCode}' has no receivable account set";
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
                'customer_id' => $customer?->id,
                'invoice_number' => $invNo,
                'invoice_date' => $invDate,
                'due_date' => $this->date($row, 'due_date'),
                'outstanding_amount' => $amount,
                'reference' => $this->str($row, 'reference'),
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $this->service->postOpeningReceivable($data, $userId);

        return (string) $data['invoice_number'];
    }
}
