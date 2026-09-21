<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ChartOfAccountRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Root accounts with eager-loaded descendant tree.
     */
    public function tree(): Collection;

    public function codeExists(string $code, ?int $exceptId = null): bool;

    public function hasPostings(int $accountId): bool;
}
