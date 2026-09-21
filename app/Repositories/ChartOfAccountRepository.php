<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ChartOfAccount;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

final class ChartOfAccountRepository extends BaseRepository implements ChartOfAccountRepositoryInterface
{
    public function __construct(ChartOfAccount $model)
    {
        parent::__construct($model);
    }

    protected function filterable(): array
    {
        return ['status', 'account_type', 'is_postable', 'parent_id'];
    }

    /**
     * Full account hierarchy to any depth, assembled in memory from a single
     * query (the live COA runs 7 levels deep, so fixed eager-load nesting
     * would truncate it). Each node carries its `children` relation set, so
     * ChartOfAccountResource renders the tree recursively.
     */
    public function tree(): Collection
    {
        $all = ChartOfAccount::query()->orderBy('code')->get();

        // Group children by parent id; roots are grouped under 0.
        $byParent = $all->groupBy(fn (ChartOfAccount $a) => $a->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent): Collection {
            return ($byParent->get($parentId) ?? new Collection())
                ->map(function (ChartOfAccount $node) use (&$build): ChartOfAccount {
                    $node->setRelation('children', $build($node->id));

                    return $node;
                })
                ->values();
        };

        return $build(0);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return ChartOfAccount::query()
            ->where('code', $code)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function hasPostings(int $accountId): bool
    {
        return ChartOfAccount::query()->findOrFail($accountId)->glTransactions()->exists();
    }
}
