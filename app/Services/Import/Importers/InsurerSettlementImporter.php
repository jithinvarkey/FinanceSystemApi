<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Enums\InsurerSettlementStatus;
use App\Enums\VendorType;
use App\Models\InsurerSettlement;
use App\Services\DocumentNumberService;
use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;
use Illuminate\Support\Carbon;

/**
 * Historical insurer settlements (payments to insurers) — recorded against an
 * imported insurer as a posted record for bordereaux/statement continuity. No
 * GL re-post (the cash position is carried by the opening balances).
 */
final class InsurerSettlementImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function key(): string
    {
        return 'historical-settlements';
    }

    public function label(): string
    {
        return 'Historical insurer settlements (payments)';
    }

    public function group(): string
    {
        return 'History';
    }

    public function order(): int
    {
        return 110;
    }

    public function columns(): array
    {
        return [
            ['name' => 'insurer_code', 'required' => true, 'hint' => 'Vendor code of an insurer'],
            ['name' => 'settlement_date', 'required' => true],
            ['name' => 'amount', 'required' => true, 'hint' => 'Net premium settled to the insurer'],
            ['name' => 'bank_account_code', 'required' => true, 'hint' => 'GL code of the paying bank account'],
            ['name' => 'payment_method', 'required' => false, 'hint' => 'bank_transfer (default) / cheque / cash / online'],
            ['name' => 'reference', 'required' => false],
            ['name' => 'settlement_number', 'required' => false, 'hint' => 'Original number (auto-generated if blank)'],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $insurer = $this->findVendor($this->str($row, 'insurer_code'), VendorType::Insurer);
        $date = $this->date($row, 'settlement_date');
        $amount = $this->num($row, 'amount');
        $bankCode = $this->str($row, 'bank_account_code');
        $bank = $this->findAccount($bankCode);
        $number = $this->str($row, 'settlement_number');

        if (! $insurer) {
            $errors[] = "insurer_code '".$this->str($row, 'insurer_code')."' not found or not an insurer";
        }
        if (! $date) {
            $errors[] = 'settlement_date is required (valid date)';
        }
        if (! $amount || $amount <= 0) {
            $errors[] = 'amount must be a positive number';
        }
        if (! $bankCode) {
            $errors[] = 'bank_account_code is required';
        } elseif (! $bank) {
            $errors[] = "bank_account_code '{$bankCode}' not found";
        }
        if ($number && InsurerSettlement::query()->where('settlement_number', $number)->exists()) {
            $errors[] = "settlement_number '{$number}' already exists";
        }

        return [
            'errors' => $errors,
            'data' => [
                'insurer_id' => $insurer?->id,
                'settlement_date' => $date,
                'amount' => $amount,
                'bank_account_id' => $bank?->id,
                'payment_method' => $this->str($row, 'payment_method') ?? 'bank_transfer',
                'reference' => $this->str($row, 'reference'),
                'settlement_number' => $number,
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $date = Carbon::parse($data['settlement_date']);
        $number = $data['settlement_number'] ?: $this->numbers->next('insurer_settlement', $date);

        InsurerSettlement::query()->create([
            'settlement_number' => $number,
            'insurer_id' => $data['insurer_id'],
            'settlement_date' => $date->toDateString(),
            'bank_account_id' => $data['bank_account_id'],
            'payment_method' => $data['payment_method'],
            'reference' => $data['reference'],
            'currency_code' => 'SAR',
            'exchange_rate' => 1,
            'amount' => round((float) $data['amount'], 2),
            'status' => InsurerSettlementStatus::Posted,
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);

        return $number;
    }
}
