<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChartOfAccountRequest;
use App\Http\Resources\ChartOfAccountResource;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Services\ChartOfAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FIN-0001 — Chart of accounts API. Thin controller: rules live in the service.
 */
final class ChartOfAccountController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountService $service,
        private readonly ChartOfAccountRepositoryInterface $accounts,
    ) {
    }

    /**
     * GET /api/v1/finance-config/accounts — paginated flat list with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $page = $this->accounts->paginate(
            filters: $request->only(['status', 'account_type', 'is_postable', 'search']),
            perPage: min((int) $request->integer('per_page', 25), 100),
        );

        return ChartOfAccountResource::collection($page);
    }

    /**
     * GET /api/v1/finance-config/accounts/tree — full hierarchy for the tree view.
     */
    public function tree(): AnonymousResourceCollection
    {
        return ChartOfAccountResource::collection($this->service->tree());
    }

    /**
     * POST /api/v1/finance-config/accounts
     */
    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $account = $this->service->create($request->validated(), (int) $request->user()->id);

        return (new ChartOfAccountResource($account))->response()->setStatusCode(201);
    }

    /**
     * GET /api/v1/finance-config/accounts/{id}
     */
    public function show(int $id): ChartOfAccountResource
    {
        return new ChartOfAccountResource($this->accounts->findByIdOrFail($id, ['parent', 'children']));
    }

    /**
     * PUT /api/v1/finance-config/accounts/{id}
     */
    public function update(StoreChartOfAccountRequest $request, int $id): ChartOfAccountResource
    {
        return new ChartOfAccountResource($this->service->update($id, $request->validated()));
    }

    /**
     * DELETE /api/v1/finance-config/accounts/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(null, 204);
    }
}
