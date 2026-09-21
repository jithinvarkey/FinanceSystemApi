<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaxCodeRequest;
use App\Http\Resources\TaxCodeResource;
use App\Repositories\Contracts\TaxCodeRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FIN-0004 — VAT / tax configuration.
 */
final class TaxCodeController extends Controller
{
    public function __construct(
        private readonly TaxCodeRepositoryInterface $taxCodes,
    ) {
    }

    /**
     * GET /api/v1/finance-config/tax-codes
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TaxCodeResource::collection(
            $this->taxCodes->paginate(
                filters: $request->only(['status', 'tax_type', 'search']),
                with: ['inputAccount', 'outputAccount'],
            ),
        );
    }

    /**
     * POST /api/v1/finance-config/tax-codes
     */
    public function store(StoreTaxCodeRequest $request): JsonResponse
    {
        return (new TaxCodeResource($this->taxCodes->create($request->validated())))
            ->response()->setStatusCode(201);
    }

    /**
     * PUT /api/v1/finance-config/tax-codes/{id}
     */
    public function update(StoreTaxCodeRequest $request, int $id): TaxCodeResource
    {
        return new TaxCodeResource($this->taxCodes->update($id, $request->validated()));
    }

    /**
     * DELETE /api/v1/finance-config/tax-codes/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $this->taxCodes->delete($id);

        return response()->json(null, 204);
    }
}
