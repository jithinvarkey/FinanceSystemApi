<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Common persistence contract shared by all finance repositories.
 */
interface BaseRepositoryInterface
{
    public function findById(int $id, array $with = []): ?Model;

    public function findByIdOrFail(int $id, array $with = []): Model;

    /**
     * @param array<string, mixed> $filters Column => value filters
     * @param list<string> $with Relations to eager load
     * @param list<string> $withCount Relations to count (exposed as <relation>_count)
     */
    public function paginate(array $filters = [], array $with = [], int $perPage = 25, array $withCount = []): LengthAwarePaginator;

    public function all(array $filters = [], array $with = []): Collection;

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Model;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Model;

    public function delete(int $id): bool;
}
