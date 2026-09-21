<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\BenefitCost;
use App\Models\EmployeeBenefit;
use App\Models\PayrollSetting;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W2 — Employee benefits. Air-ticket entitlements accrue a monthly provision
 * (Dr expense / Cr provision); recording an actual cost releases the provision
 * (or, for medical/visa/iqama, expenses it directly) against a benefit payable.
 */
final class BenefitService
{
    use ResolvesPostableAccount;

    private const ACCRUING = ['air_ticket'];

    public function __construct(private readonly GlPostingService $posting)
    {
    }

    /**
     * Accrue one month of provision for every active accruing benefit.
     *
     * @return array{accrued: int, amount: string}
     */
    public function accrueMonth(int $year, int $month, Carbon $date, int $userId): array
    {
        $s = PayrollSetting::current();
        $expId = $this->required($s->airticket_expense_account_id, 'air-ticket expense');
        $provId = $this->required($s->airticket_provision_account_id, 'air-ticket provision');

        $benefits = EmployeeBenefit::query()
            ->whereIn('benefit_type', self::ACCRUING)->where('status', 'active')->where('annual_amount', '>', 0)
            ->get()
            ->filter(fn (EmployeeBenefit $b): bool => ! $this->alreadyAccrued($b, $year, $month));

        if ($benefits->isEmpty()) {
            return ['accrued' => 0, 'amount' => '0.00'];
        }

        return DB::transaction(function () use ($benefits, $expId, $provId, $date, $year, $month, $userId): array {
            $total = 0.0;
            $perBenefit = [];
            foreach ($benefits as $b) {
                $monthly = round((float) $b->annual_amount / 12, 2);
                $perBenefit[$b->id] = $monthly;
                $total = round($total + $monthly, 2);
            }

            $rows = $this->posting->post(PayrollSetting::current(), $date, [
                new PostingLine($expId, $total, 0.0, 'SAR', 1.0, null, "Air-ticket provision accrual {$year}-{$month}"),
                new PostingLine($provId, 0.0, $total, 'SAR', 1.0, null, "Air-ticket provision {$year}-{$month}"),
            ], $userId);
            $batch = $rows->first()->batch_number;

            foreach ($benefits as $b) {
                $monthly = $perBenefit[$b->id];
                BenefitCost::query()->create([
                    'employee_benefit_id' => $b->id, 'cost_date' => $date->toDateString(), 'amount' => $monthly,
                    'kind' => 'accrual', 'description' => "Monthly accrual {$year}-{$month}", 'batch_number' => $batch, 'created_by' => $userId,
                ]);
                $b->increment('accrued_amount', $monthly);
            }

            return ['accrued' => $benefits->count(), 'amount' => number_format($total, 2, '.', '')];
        });
    }

    /** Record an actual benefit cost (ticket booked, medical premium, visa fee…). */
    public function recordCost(EmployeeBenefit $benefit, float $amount, Carbon $date, ?string $description, int $userId): BenefitCost
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new FinanceRuleException('Cost amount must be positive.');
        }
        $s = PayrollSetting::current();
        $payableId = $this->required($s->benefit_payable_account_id, 'benefit payable');

        $lines = [];
        $kind = 'expense';
        if (in_array($benefit->benefit_type, self::ACCRUING, true)) {
            $kind = 'utilization';
            $provId = $this->required($s->airticket_provision_account_id, 'air-ticket provision');
            $expId = $this->required($s->airticket_expense_account_id, 'air-ticket expense');
            $fromProvision = min($amount, max($benefit->outstandingProvision(), 0));
            $excess = round($amount - $fromProvision, 2);
            if ($fromProvision > 0) {
                $lines[] = new PostingLine($provId, $fromProvision, 0.0, 'SAR', 1.0, null, 'Air-ticket provision release');
            }
            if ($excess > 0) {
                $lines[] = new PostingLine($expId, $excess, 0.0, 'SAR', 1.0, null, 'Air-ticket cost (over provision)');
            }
        } else {
            $expId = $this->required($s->benefit_expense_account_id, 'benefit expense');
            $lines[] = new PostingLine($expId, $amount, 0.0, 'SAR', 1.0, null, ucfirst($benefit->benefit_type).' cost');
        }
        $lines[] = new PostingLine($payableId, 0.0, $amount, 'SAR', 1.0, null, 'Benefit payable');

        return DB::transaction(function () use ($benefit, $amount, $date, $description, $kind, $lines, $userId): BenefitCost {
            $rows = $this->posting->post(PayrollSetting::current(), $date, $lines, $userId);
            $benefit->increment('utilized_amount', $amount);

            return BenefitCost::query()->create([
                'employee_benefit_id' => $benefit->id, 'cost_date' => $date->toDateString(), 'amount' => $amount,
                'kind' => $kind, 'description' => $description, 'batch_number' => $rows->first()->batch_number, 'created_by' => $userId,
            ]);
        });
    }

    private function alreadyAccrued(EmployeeBenefit $b, int $year, int $month): bool
    {
        return BenefitCost::query()->where('employee_benefit_id', $b->id)->where('kind', 'accrual')
            ->whereYear('cost_date', $year)->whereMonth('cost_date', $month)->exists();
    }

    private function required(?int $id, string $label): int
    {
        if ($id === null) {
            throw new FinanceRuleException("Configure the {$label} account in payroll settings first.");
        }

        return $this->resolvePostable($id);
    }
}
