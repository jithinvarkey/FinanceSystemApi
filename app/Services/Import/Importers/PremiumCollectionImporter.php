<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Enums\PremiumCollectionStatus;
use App\Models\Policy;
use App\Models\PremiumCollection;
use App\Services\DocumentNumberService;
use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;
use Illuminate\Support\Carbon;

/**
 * Historical premium collections (receipts from customers) — recorded against
 * an imported policy as a posted record for SOA continuity. No GL re-post (the
 * cash position is carried by the opening balances).
 */
final class PremiumCollectionImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function key(): string
    {
        return 'historical-collections';
    }

    public function label(): string
    {
        return 'Historical premium collections (receipts)';
    }

    public function group(): string
    {
        return 'History';
    }

    public function order(): int
    {
        return 100;
    }

    public function columns(): array
    {
        return [
            ['name' => 'policy_number', 'required' => true, 'hint' => 'An imported/existing policy'],
            ['name' => 'collection_date', 'required' => true],
            ['name' => 'amount', 'required' => true, 'hint' => 'Gross premium collected'],
            ['name' => 'bank_account_code', 'required' => true, 'hint' => 'GL code of the receiving bank account'],
            ['name' => 'payment_method', 'required' => false, 'hint' => 'bank_transfer (default) / cheque / cash / online'],
            ['name' => 'reference', 'required' => false],
            ['name' => 'collection_number', 'required' => false, 'hint' => 'Original number (auto-generated if blank)'],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $policy = Policy::query()->where('policy_number', $this->str($row, 'policy_number'))->first();
        $date = $this->date($row, 'collection_date');
        $amount = $this->num($row, 'amount');
        $bankCode = $this->str($row, 'bank_account_code');
        $bank = $this->findAccount($bankCode);
        $number = $this->str($row, 'collection_number');

        if (! $policy) {
            $errors[] = "policy_number '".$this->str($row, 'policy_number')."' not found";
        }
        if (! $date) {
            $errors[] = 'collection_date is required (valid date)';
        }
        if (! $amount || $amount <= 0) {
            $errors[] = 'amount must be a positive number';
        }
        if (! $bankCode) {
            $errors[] = 'bank_account_code is required';
        } elseif (! $bank) {
            $errors[] = "bank_account_code '{$bankCode}' not found";
        }
        if ($number && PremiumCollection::query()->where('collection_number', $number)->exists()) {
            $errors[] = "collection_number '{$number}' already exists";
        }

        return [
            'errors' => $errors,
            'data' => [
                'policy_id' => $policy?->id,
                'collection_date' => $date,
                'amount' => $amount,
                'bank_account_id' => $bank?->id,
                'payment_method' => $this->str($row, 'payment_method') ?? 'bank_transfer',
                'reference' => $this->str($row, 'reference'),
                'collection_number' => $number,
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $date = Carbon::parse($data['collection_date']);
        $number = $data['collection_number'] ?: $this->numbers->next('premium_collection', $date);

        PremiumCollection::query()->create([
            'collection_number' => $number,
            'policy_id' => $data['policy_id'],
            'collection_date' => $date->toDateString(),
            'bank_account_id' => $data['bank_account_id'],
            'payment_method' => $data['payment_method'],
            'reference' => $data['reference'],
            'currency_code' => 'SAR',
            'exchange_rate' => 1,
            'amount' => round((float) $data['amount'], 2),
            'status' => PremiumCollectionStatus::Posted,
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);

        return $number;
    }
}
