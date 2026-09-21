<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\AllocationRule;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F26 — Allocation journals. Spreads an amount from a source account/cost-centre
 * across target cost centres by percentage, posting a balanced reclassification:
 *   Cr source account (source cost centre)
 *   Dr target account (each target cost centre, amount × share%)
 */
final class AllocationService
{
    use ResolvesPostableAccount;

    public function __construct(private readonly GlPostingService $posting)
    {
    }

    /** @param array<string,mixed> $data */
    public function createRule(array $data, int $userId): AllocationRule
    {
        $this->assertSharesAreValid($data['lines'] ?? []);

        return DB::transaction(function () use ($data, $userId): AllocationRule {
            $rule = AllocationRule::query()->create([
                'name' => $data['name'],
                'source_account_id' => $data['source_account_id'],
                'target_account_id' => $data['target_account_id'],
                'source_cost_center_id' => $data['source_cost_center_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $userId,
            ]);

            $rule->lines()->createMany(array_map(fn (array $l): array => [
                'cost_center_id' => (int) $l['cost_center_id'],
                'percentage' => round((float) $l['percentage'], 4),
            ], $data['lines']));

            return $rule->load('lines');
        });
    }

    /** @param array<string,mixed> $data */
    public function updateRule(AllocationRule $rule, array $data): AllocationRule
    {
        $this->assertSharesAreValid($data['lines'] ?? []);

        return DB::transaction(function () use ($rule, $data): AllocationRule {
            $rule->update([
                'name' => $data['name'],
                'source_account_id' => $data['source_account_id'],
                'target_account_id' => $data['target_account_id'],
                'source_cost_center_id' => $data['source_cost_center_id'] ?? null,
                'is_active' => $data['is_active'] ?? $rule->is_active,
            ]);
            $rule->lines()->delete();
            $rule->lines()->createMany(array_map(fn (array $l): array => [
                'cost_center_id' => (int) $l['cost_center_id'],
                'percentage' => round((float) $l['percentage'], 4),
            ], $data['lines']));

            return $rule->load('lines');
        });
    }

    /**
     * Run a rule for $amount on $date. Posts the reclassification and returns the
     * GL batch number.
     */
    public function run(AllocationRule $rule, float $amount, Carbon $date, int $userId): string
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new FinanceRuleException('The amount to allocate must be positive.');
        }
        $rule->loadMissing('lines');
        if ($rule->lines->isEmpty()) {
            throw new FinanceRuleException('This allocation rule has no target lines.');
        }

        $sourceId = $this->resolvePostable((int) $rule->source_account_id);
        $targetId = $this->resolvePostable((int) $rule->target_account_id);

        return DB::transaction(function () use ($rule, $amount, $sourceId, $targetId, $date, $userId): string {
            $lines = [
                new PostingLine($sourceId, 0.0, $amount, 'SAR', 1.0, $rule->source_cost_center_id, 'Allocation source — '.$rule->name),
            ];

            // Spread, fixing any rounding pennies onto the last target.
            $allocated = 0.0;
            $count = $rule->lines->count();
            foreach ($rule->lines->values() as $i => $line) {
                $share = $i === $count - 1
                    ? round($amount - $allocated, 2)
                    : round($amount * (float) $line->percentage / 100, 2);
                $allocated = round($allocated + $share, 2);

                $lines[] = new PostingLine($targetId, $share, 0.0, 'SAR', 1.0, (int) $line->cost_center_id, 'Allocation — '.$rule->name);
            }

            $rows = $this->posting->post($rule, $date, $lines, $userId);

            return (string) $rows->first()->batch_number;
        });
    }

    /** @param array<int,array<string,mixed>> $lines */
    private function assertSharesAreValid(array $lines): void
    {
        if (count($lines) < 1) {
            throw new FinanceRuleException('An allocation rule needs at least one target.');
        }
        $sum = round(array_sum(array_map(fn ($l): float => (float) $l['percentage'], $lines)), 2);
        if (abs($sum - 100.0) >= 0.01) {
            throw new FinanceRuleException("The target shares must total 100% (currently {$sum}%).");
        }
    }
}
