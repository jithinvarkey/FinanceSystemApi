<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\Policy;
use App\Models\PremiumInstallment;
use App\Models\PremiumInstallmentPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * N1 — Premium installment management. Splits a policy's gross premium into a
 * schedule of dated installments and tracks collection per installment.
 */
final class PremiumInstallmentService
{
    private const FREQUENCY_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6];

    public function createPlan(Policy $policy, int $count, string $frequency, Carbon $firstDue, ?float $totalAmount, int $userId): PremiumInstallmentPlan
    {
        if ($count < 1) {
            throw new FinanceRuleException('A plan needs at least one installment.');
        }
        $step = self::FREQUENCY_MONTHS[$frequency] ?? null;
        if ($step === null) {
            throw new FinanceRuleException("Unknown frequency '{$frequency}'.");
        }
        if (PremiumInstallmentPlan::query()->where('policy_id', $policy->id)->where('status', 'active')->exists()) {
            throw new FinanceRuleException('This policy already has an active installment plan.');
        }

        $total = round($totalAmount ?? (float) $policy->gross_premium, 2);
        if ($total <= 0) {
            throw new FinanceRuleException('The premium to schedule must be positive.');
        }

        $per = round($total / $count, 2);

        return DB::transaction(function () use ($policy, $count, $frequency, $step, $firstDue, $total, $per, $userId): PremiumInstallmentPlan {
            $plan = PremiumInstallmentPlan::query()->create([
                'policy_id' => $policy->id, 'installments' => $count, 'frequency' => $frequency,
                'start_date' => $firstDue->toDateString(), 'total_amount' => $total, 'status' => 'active', 'created_by' => $userId,
            ]);

            $allocated = 0.0;
            for ($i = 1; $i <= $count; $i++) {
                $amount = $i === $count ? round($total - $allocated, 2) : $per;
                $allocated = round($allocated + $amount, 2);
                $plan->items()->create([
                    'policy_id' => $policy->id, 'installment_no' => $i,
                    'due_date' => $firstDue->copy()->addMonths($step * ($i - 1))->toDateString(),
                    'amount' => $amount, 'amount_paid' => 0, 'status' => 'pending',
                ]);
            }

            return $plan->load('items');
        });
    }

    /** Record a (non-GL) collection against one installment; cash still posts via premium collections. */
    public function recordPayment(PremiumInstallment $installment, float $amount, int $userId): PremiumInstallment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new FinanceRuleException('Payment amount must be positive.');
        }
        if ($amount > $installment->balance() + 0.01) {
            throw new FinanceRuleException('Payment exceeds the installment balance.');
        }

        return DB::transaction(function () use ($installment, $amount): PremiumInstallment {
            $paid = round((float) $installment->amount_paid + $amount, 2);
            $installment->update([
                'amount_paid' => $paid,
                'status' => $paid >= (float) $installment->amount - 0.01 ? 'paid' : 'partial',
            ]);

            $plan = $installment->plan;
            if (! $plan->items()->where('status', '!=', 'paid')->exists()) {
                $plan->update(['status' => 'completed']);
            }

            return $installment->fresh();
        });
    }

    /**
     * Upcoming + overdue installments across all active plans.
     *
     * @return array{as_of: string, overdue: list<array<string,mixed>>, upcoming: list<array<string,mixed>>, overdue_total: float, upcoming_total: float}
     */
    public function dueReport(Carbon $asOf, int $horizonDays = 30): array
    {
        $unpaid = PremiumInstallment::query()
            ->where('status', '!=', 'paid')
            ->with('plan.policy:id,policy_number')
            ->orderBy('due_date')->get();

        $overdue = [];
        $upcoming = [];
        $overdueTotal = 0.0;
        $upcomingTotal = 0.0;
        $horizon = $asOf->copy()->addDays($horizonDays);

        foreach ($unpaid as $inst) {
            $row = [
                'id' => $inst->id, 'policy_number' => $inst->plan?->policy?->policy_number,
                'installment_no' => $inst->installment_no, 'due_date' => $inst->due_date?->toDateString(),
                'amount' => $inst->amount, 'amount_paid' => $inst->amount_paid, 'balance' => $inst->balance(),
            ];
            if ($inst->due_date !== null && $inst->due_date->lt($asOf)) {
                $row['days_overdue'] = (int) $inst->due_date->diffInDays($asOf);
                $overdue[] = $row;
                $overdueTotal = round($overdueTotal + $inst->balance(), 2);
            } elseif ($inst->due_date !== null && $inst->due_date->lte($horizon)) {
                $upcoming[] = $row;
                $upcomingTotal = round($upcomingTotal + $inst->balance(), 2);
            }
        }

        return ['as_of' => $asOf->toDateString(), 'overdue' => $overdue, 'upcoming' => $upcoming, 'overdue_total' => $overdueTotal, 'upcoming_total' => $upcomingTotal];
    }
}
