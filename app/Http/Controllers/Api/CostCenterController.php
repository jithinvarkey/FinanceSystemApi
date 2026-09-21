<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCostCenterRequest;
use App\Http\Resources\CostCenterResource;
use App\Repositories\Contracts\CostCenterRepositoryInterface;
use App\Services\CostCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FIN-0005 — Cost centers.
 */
final class CostCenterController extends Controller
{
    public function __construct(
        private readonly CostCenterService $service,
        private readonly CostCenterRepositoryInterface $costCenters,
    ) {
    }

    /**
     * GET /api/v1/finance-config/cost-centers
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CostCenterResource::collection(
            $this->costCenters->paginate(
                filters: $request->only(['status', 'search']),
                with: ['children'],
            ),
        );
    }

    /**
     * POST /api/v1/finance-config/cost-centers
     */
    public function store(StoreCostCenterRequest $request): JsonResponse
    {
        return (new CostCenterResource($this->service->create($request->validated())))
            ->response()->setStatusCode(201);
    }

    /**
     * PUT /api/v1/finance-config/cost-centers/{id}
     */
    public function update(StoreCostCenterRequest $request, int $id): CostCenterResource
    {
        return new CostCenterResource($this->service->update($id, $request->validated()));
    }

    /**
     * DELETE /api/v1/finance-config/cost-centers/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(null, 204);
    }
}
