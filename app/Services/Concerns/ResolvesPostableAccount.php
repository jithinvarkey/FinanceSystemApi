<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Enums\RecordStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\ChartOfAccount;

/**
 * Resolves a configured account (which may be a control/VAT header) to a real
 * postable account: the account itself if postable, else its first postable
 * descendant by code prefix. Shared by AP invoice and payment posting.
 */
trait ResolvesPostableAccount
{
    /** @throws FinanceRuleException */
    protected function resolvePostable(int $accountId): int
    {
        $account = ChartOfAccount::query()->find($accountId);

        if ($account === null) {
            throw FinanceRuleException::accountNotPostable("#{$accountId}");
        }

        if ($account->is_postable && $account->status === RecordStatus::Active) {
            return $account->id;
        }

        $leaf = ChartOfAccount::query()
            ->where('code', 'like', $account->code.'%')
            ->where('is_postable', true)
            ->where('status', RecordStatus::Active->value)
            ->orderBy('code')
            ->first();

        return $leaf?->id ?? throw FinanceRuleException::accountNotPostable($account->code);
    }
}
