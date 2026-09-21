<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Enums\EndorsementStatus;
use App\Enums\EndorsementType;
use App\Models\Policy;
use App\Models\TaxCode;
use App\Services\DocumentNumberService;
use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;
use Illuminate\Support\Carbon;

/**
 * Historical endorsements — attached to an already-imported policy (by policy
 * number) as a posted record. Like historical policies, these do NOT re-post
 * GL (the financial position is carried by the opening balances). Deltas derive
 * from the policy's own tax rate and commission rate.
 */
final class EndorsementImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function key(): string
    {
        return 'historical-endorsements';
    }

    public function label(): string
    {
        return 'Historical endorsements';
    }

    public function group(): string
    {
        return 'History';
    }

    public function order(): int
    {
        return 90;
    }

    public function columns(): array
    {
        return [
            ['name' => 'policy_number', 'required' => true, 'hint' => 'An imported/existing policy'],
            ['name' => 'type', 'required' => true, 'hint' => 'addition / deletion / upgrade / downgrade'],
            ['name' => 'effective_date', 'required' => true],
            ['name' => 'delta_net_premium', 'required' => true, 'hint' => 'Positive amount; type sets the direction'],
            ['name' => 'endorsement_number', 'required' => false, 'hint' => 'Original number (auto-generated if blank)'],
            ['name' => 'reason', 'required' => false],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $policy = Policy::query()->where('policy_number', $this->str($row, 'policy_number'))->first();
        $typeRaw = mb_strtolower((string) ($this->str($row, 'type') ?? ''));
        $type = EndorsementType::tryFrom($typeRaw);
        $effective = $this->date($row, 'effective_date');
        $net = $this->num($row, 'delta_net_premium');
        $number = $this->str($row, 'endorsement_number');

        if (! $policy) {
            $errors[] = "policy_number '".$this->str($row, 'policy_number')."' not found (import policies first)";
        }
        if (! $type) {
            $errors[] = "type must be addition, deletion, upgrade or downgrade (got '{$typeRaw}')";
        }
        if (! $effective) {
            $errors[] = 'effective_date is required (valid date)';
        }
        if (! $net || $net <= 0) {
            $errors[] = 'delta_net_premium must be a positive number';
        }
        if ($number && \App\Models\PolicyEndorsement::query()->where('endorsement_number', $number)->exists()) {
            $errors[] = "endorsement_number '{$number}' already exists";
        }

        return [
            'errors' => $errors,
            'data' => [
                'policy_id' => $policy?->id,
                'tax_code_id' => $policy?->tax_code_id,
                'commission_rate' => (float) ($policy?->commission_rate ?? 0),
                'type' => $type?->value,
                'effective_date' => $effective,
                'delta_net_premium' => $net,
                'endorsement_number' => $number,
                'reason' => $this->str($row, 'reason'),
            ],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $type = EndorsementType::from($data['type']);
        $effective = Carbon::parse($data['effective_date']);
        $vatRate = $data['tax_code_id'] ? (float) (TaxCode::query()->whereKey($data['tax_code_id'])->value('rate') ?? 0) : 0.0;

        $net = round((float) $data['delta_net_premium'], 2);
        $premiumTax = round($net * $vatRate / 100, 2);
        $commission = round($net * (float) $data['commission_rate'] / 100, 2);
        $commissionTax = round($commission * $vatRate / 100, 2);

        $number = $data['endorsement_number'] ?: $this->numbers->next('endorsement', $effective);

        $endorsement = Policy::query()->whereKey($data['policy_id'])->first()->endorsements()->create([
            'endorsement_number' => $number,
            'source_system' => 'import',
            'external_id' => $number,
            'type' => $type,
            'direction' => $type->direction(),
            'effective_date' => $effective->toDateString(),
            'reason' => $data['reason'],
            'delta_net_premium' => $net,
            'delta_premium_tax' => $premiumTax,
            'delta_gross' => round($net + $premiumTax, 2),
            'delta_commission' => $commission,
            'delta_commission_tax' => $commissionTax,
            'status' => EndorsementStatus::Posted,
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);

        return $endorsement->endorsement_number;
    }
}
