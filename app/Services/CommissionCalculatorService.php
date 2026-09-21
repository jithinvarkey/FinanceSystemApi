<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CommissionRule;

/**
 * E5 — resolves the commission rate for a policy from the rule set. The most
 * specific active rule wins (insurer+product > product > insurer > global);
 * renewal rules take precedence for renewed policies; tiered rules pick the band
 * matching the net premium.
 */
final class CommissionCalculatorService
{
    /**
     * @return array{rate: float, commission: float, rule_id: ?int, rule_name: ?string, basis: string}
     */
    public function resolve(?int $insurerId, ?int $productId, float $netPremium, bool $isRenewal): array
    {
        $candidates = CommissionRule::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('insurer_id')->orWhere('insurer_id', $insurerId))
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $productId))
            ->with('tiers')
            ->get();

        // Renewal policies prefer renewal rules; otherwise exclude them.
        $pool = $isRenewal
            ? ($candidates->where('rule_type', 'renewal')->isNotEmpty() ? $candidates->where('rule_type', 'renewal') : $candidates->where('rule_type', '!=', 'renewal'))
            : $candidates->where('rule_type', '!=', 'renewal');

        $specificity = fn (CommissionRule $r): int => ($r->insurer_id !== null ? 2 : 0) + ($r->product_id !== null ? 1 : 0);
        $rule = $pool->sortByDesc($specificity)->first();

        if ($rule === null) {
            return ['rate' => 0.0, 'commission' => 0.0, 'rule_id' => null, 'rule_name' => null, 'basis' => 'none'];
        }

        $rate = $this->rateFor($rule, $netPremium);

        return [
            'rate' => round($rate, 4),
            'commission' => round($netPremium * $rate / 100, 2),
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'basis' => $rule->rule_type,
        ];
    }

    private function rateFor(CommissionRule $rule, float $netPremium): float
    {
        if ($rule->rule_type === 'tiered') {
            $tier = $rule->tiers
                ->filter(fn ($t): bool => (float) $t->min_premium <= $netPremium)
                ->sortByDesc(fn ($t): float => (float) $t->min_premium)
                ->first();

            return $tier !== null ? (float) $tier->rate : 0.0;
        }

        return (float) $rule->rate; // flat or renewal
    }
}
