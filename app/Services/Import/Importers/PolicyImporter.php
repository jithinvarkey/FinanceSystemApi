<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Enums\PolicyStatus;
use App\Models\Policy;
use App\Models\TaxCode;
use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;

/**
 * Historical policies — the policy register as it stood before cutover, for
 * reporting/SOA continuity. These are imported as a settled record and do NOT
 * re-post the issuance GL: the financial position is already carried by the
 * opening trial balance + open AR/AP sheets, so re-posting would double-count.
 * The original policy number is preserved; premium is marked fully collected
 * and the insurer fully settled (so the policy shows no live outstanding —
 * anything still owed must come through the Open AR / Open AP sheets).
 */
final class PolicyImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function key(): string
    {
        return 'historical-policies';
    }

    public function label(): string
    {
        return 'Historical policies';
    }

    public function group(): string
    {
        return 'History';
    }

    public function order(): int
    {
        return 80;
    }

    public function columns(): array
    {
        return [
            ['name' => 'policy_number', 'required' => true, 'hint' => 'Original policy number (kept as-is)'],
            ['name' => 'customer_code', 'required' => true],
            ['name' => 'insurer_code', 'required' => true, 'hint' => 'Vendor code of an insurer'],
            ['name' => 'product_code', 'required' => true],
            ['name' => 'start_date', 'required' => true],
            ['name' => 'end_date', 'required' => true],
            ['name' => 'net_premium', 'required' => true],
            ['name' => 'commission_rate', 'required' => false, 'hint' => 'Percent; defaults to the product rate'],
            ['name' => 'insurer_policy_no', 'required' => false],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $number = $this->str($row, 'policy_number');
        $customer = $this->findCustomer($this->str($row, 'customer_code'));
        $insurer = $this->findVendor($this->str($row, 'insurer_code'), \App\Enums\VendorType::Insurer);
        $product = $this->findProduct($this->str($row, 'product_code'));
        $start = $this->date($row, 'start_date');
        $end = $this->date($row, 'end_date');
        $net = $this->num($row, 'net_premium');

        if (! $number) {
            $errors[] = 'policy_number is required';
        } elseif (Policy::query()->where('policy_number', $number)->exists()) {
            $errors[] = "policy_number '{$number}' already exists";
        }
        if (! $customer) {
            $errors[] = "customer_code '".$this->str($row, 'customer_code')."' not found (import customers first)";
        }
        if (! $insurer) {
            $errors[] = "insurer_code '".$this->str($row, 'insurer_code')."' not found or not an insurer";
        }
        if (! $product) {
            $errors[] = "product_code '".$this->str($row, 'product_code')."' not found (import products first)";
        }
        if (! $start) {
            $errors[] = 'start_date is required (valid date)';
        }
        if (! $end) {
            $errors[] = 'end_date is required (valid date)';
        }
        if (! $net || $net <= 0) {
            $errors[] = 'net_premium must be a positive number';
        }

        $rate = $this->num($row, 'commission_rate');
        $commissionRate = $rate ?? (float) ($product?->default_commission_rate ?? 0);

        return [
            'errors' => $errors,
            'data' => [
                'policy_number' => $number,
                'customer_id' => $customer?->id,
                'insurer_id' => $insurer?->id,
                'product_id' => $product?->id,
                'tax_code_id' => $product?->default_tax_code_id,
                'start_date' => $start,
                'end_date' => $end,
                'net_premium' => $net,
                'commission_rate' => $commissionRate,
                'insurer_policy_no' => $this->str($row, 'insurer_policy_no'),
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $taxRate = $data['tax_code_id']
            ? (float) (TaxCode::query()->whereKey($data['tax_code_id'])->value('rate') ?? 0)
            : 0.0;

        $net = round((float) $data['net_premium'], 2);
        $premiumTax = round($net * $taxRate / 100, 2);
        $gross = round($net + $premiumTax, 2);
        $commission = round($net * (float) $data['commission_rate'] / 100, 2);
        $commissionTax = round($commission * $taxRate / 100, 2);
        $netDueInsurer = round($gross - $commission - $commissionTax, 2);

        Policy::query()->create([
            'policy_number' => $data['policy_number'],
            'source_system' => 'import',
            'external_id' => $data['policy_number'],
            'insurer_policy_no' => $data['insurer_policy_no'],
            'customer_id' => $data['customer_id'],
            'insurer_id' => $data['insurer_id'],
            'product_id' => $data['product_id'],
            'tax_code_id' => $data['tax_code_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'currency_code' => 'SAR',
            'exchange_rate' => 1,
            'payment_method' => 'full',
            'net_premium' => $net,
            'premium_tax_amount' => $premiumTax,
            'gross_premium' => $gross,
            'commission_rate' => $data['commission_rate'],
            'commission_amount' => $commission,
            'commission_tax_amount' => $commissionTax,
            'premium_collected' => $gross,        // historical record: shown settled
            'insurer_settled' => $netDueInsurer,  // outstanding (if any) comes via Open AR/AP
            'status' => PolicyStatus::Issued,
            'created_by' => $userId,
            'issued_by' => $userId,
            'issued_at' => now(),
        ]);

        return (string) $data['policy_number'];
    }
}
