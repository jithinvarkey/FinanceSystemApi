<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\CostCenter;
use App\Repositories\Contracts\CostCenterRepositoryInterface;

/**
 * FIN-0005 — Cost center rules: cycle-safe hierarchy and safe deletion.
 */
final class CostCenterService
{
    public function __construct(
        private readonly CostCenterRepositoryInterface $costCenters,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): CostCenter
    {
        /** @var CostCenter $center */
        $center = $this->costCenters->create($data);

        return $center;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws FinanceRuleException When the new parent creates a cycle
     */
    public function update(int $id, array $data): CostCenter
    {
        if (! empty($data['parent_id'])) {
            $this->assertNoCycle($id, (int) $data['parent_id']);
        }

        /** @var CostCenter $center */
        $center = $this->costCenters->update($id, $data);

        return $center;
    }

    /**
     * @throws FinanceRuleException When children exist
     */
    public function delete(int $id): void
    {
        /** @var CostCenter $center */
        $center = $this->costCenters->findByIdOrFail($id, ['children']);

        if ($center->children->isNotEmpty()) {
            throw new FinanceRuleException('Reassign child cost centers before deleting this one.');
        }

        $this->costCenters->delete($id);
    }

    /**
     * @throws FinanceRuleException
     */
    private function assertNoCycle(int $id, int $newParentId): void
    {
        if ($id === $newParentId) {
            throw new FinanceRuleException('A cost center cannot be its own parent.');
        }

        $cursor = $this->costCenters->findById($newParentId);

        while ($cursor !== null) {
            if ((int) $cursor->getKey() === $id) {
                throw new FinanceRuleException('This move would create a circular cost center hierarchy.');
            }
            $cursor = $cursor->parent_id !== null ? $this->costCenters->findById((int) $cursor->parent_id) : null;
        }
    }
}
