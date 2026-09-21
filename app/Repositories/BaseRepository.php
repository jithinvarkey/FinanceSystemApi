<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\BaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic Eloquent repository. Concrete repositories extend this and add
 * domain-specific queries; controllers never touch Eloquent directly.
 */
abstract class BaseRepository implements BaseRepositoryInterface
{
    public function __construct(protected readonly Model $model)
    {
    }

    /**
     * Columns that paginate()/all() accept as exact-match filters.
     * Override in concrete repositories.
     *
     * @return list<string>
     */
    protected function filterable(): array
    {
        return ['status'];
    }

    /**
     * Columns searched by the "search" filter via LIKE. Override as needed.
     *
     * @return list<string>
     */
    protected function searchable(): array
    {
        return ['code', 'name'];
    }

    public function findById(int $id, array $with = []): ?Model
    {
        return $this->model->newQuery()->with($with)->find($id);
    }

    public function findByIdOrFail(int $id, array $with = []): Model
    {
        return $this->model->newQuery()->with($with)->findOrFail($id);
    }

    public function paginate(array $filters = [], array $with = [], int $perPage = 25, array $withCount = []): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)->with($with)->withCount($withCount)->paginate($perPage);
    }

    public function all(array $filters = [], array $with = []): Collection
    {
        return $this->filteredQuery($filters)->with($with)->get();
    }

    public function create(array $data): Model
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(int $id, array $data): Model
    {
        $record = $this->findByIdOrFail($id);
        $record->update($data);

        return $record->refresh();
    }

    public function delete(int $id): bool
    {
        return (bool) $this->findByIdOrFail($id)->delete();
    }

    /**
     * Build a query applying whitelisted exact filters and LIKE search.
     *
     * @param array<string, mixed> $filters
     */
    protected function filteredQuery(array $filters): Builder
    {
        $query = $this->model->newQuery();

        foreach ($this->filterable() as $column) {
            if (array_key_exists($column, $filters) && $filters[$column] !== null && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                foreach ($this->searchable() as $column) {
                    $q->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        return $query;
    }
}
