<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountType;
use App\Exceptions\FinanceRuleException;
use App\Models\ChartOfAccount;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * FIN-0001 — Chart of accounts rules: code uniqueness, hierarchy depth,
 * normal-balance derivation, and deletion protection for posted accounts.
 */
final class ChartOfAccountService
{
    private const MAX_DEPTH = 4;

    public function __construct(
        private readonly ChartOfAccountRepositoryInterface $accounts,
    ) {
    }

    public function tree(): Collection
    {
        return $this->accounts->tree();
    }

    /**
     * @param array<string, mixed> $data Validated payload from StoreChartOfAccountRequest
     *
     * @throws FinanceRuleException
     */
    public function create(array $data, int $userId): ChartOfAccount
    {
        if ($this->accounts->codeExists($data['code'])) {
            throw new FinanceRuleException("Account code {$data['code']} already exists.");
        }

        $level = 1;

        if (! empty($data['parent_id'])) {
            /** @var ChartOfAccount $parent */
            $parent = $this->accounts->findByIdOrFail((int) $data['parent_id']);

            if ($parent->level >= self::MAX_DEPTH) {
                throw new FinanceRuleException('Account hierarchy is limited to ' . self::MAX_DEPTH . ' levels.');
            }

            if ($parent->account_type->value !== $data['account_type']) {
                throw new FinanceRuleException('Child accounts must share the parent account type.');
            }

            $level = $parent->level + 1;

            // A parent with children becomes a header account.
            if ($parent->is_postable) {
                $this->accounts->update($parent->id, ['is_postable' => false]);
            }
        }

        $type = AccountType::from($data['account_type']);

        return $this->accounts->create([
            ...$data,
            'normal_balance' => $type->normalBalance()->value,
            'level' => $level,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws FinanceRuleException
     */
    public function update(int $id, array $data): ChartOfAccount
    {
        if (isset($data['code']) && $this->accounts->codeExists($data['code'], $id)) {
            throw new FinanceRuleException("Account code {$data['code']} already exists.");
        }

        // Account type is immutable once the account has postings.
        if (isset($data['account_type']) && $this->accounts->hasPostings($id)) {
            /** @var ChartOfAccount $existing */
            $existing = $this->accounts->findByIdOrFail($id);
            if ($existing->account_type->value !== $data['account_type']) {
                throw new FinanceRuleException('Account type cannot change after postings exist. Create a new account instead.');
            }
        }

        return $this->accounts->update($id, $data);
    }

    /**
     * @throws FinanceRuleException When the account has ledger activity or children
     */
    public function delete(int $id): void
    {
        /** @var ChartOfAccount $account */
        $account = $this->accounts->findByIdOrFail($id, ['children']);

        if ($account->children->isNotEmpty()) {
            throw new FinanceRuleException('Delete or move child accounts first.');
        }

        if ($this->accounts->hasPostings($id)) {
            throw new FinanceRuleException('This account has ledger activity — deactivate it instead of deleting.');
        }

        $this->accounts->delete($id);
    }
}
