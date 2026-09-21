<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCurrencyRequest;
use App\Http\Requests\StoreExchangeRateRequest;
use App\Http\Resources\CurrencyResource;
use App\Repositories\Contracts\CurrencyRepositoryInterface;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FIN-0003 — Currencies and exchange rates.
 */
final class CurrencyController extends Controller
{
    public function __construct(
        private readonly CurrencyService $service,
        private readonly CurrencyRepositoryInterface $currencies,
    ) {
    }

    /**
     * GET /api/v1/finance-config/currencies
     */
    public function index(): AnonymousResourceCollection
    {
        return CurrencyResource::collection($this->currencies->all(with: ['exchangeRates']));
    }

    /**
     * POST /api/v1/finance-config/currencies
     */
    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        return (new CurrencyResource($this->service->create($request->validated())))
            ->response()->setStatusCode(201);
    }

    /**
     * PUT /api/v1/finance-config/currencies/{id}
     */
    public function update(StoreCurrencyRequest $request, int $id): CurrencyResource
    {
        return new CurrencyResource($this->service->update($id, $request->validated()));
    }

    /**
     * POST /api/v1/finance-config/currencies/{id}/rates
     */
    public function storeRate(StoreExchangeRateRequest $request, int $id): JsonResponse
    {
        $rate = $this->service->setRate(
            $id,
            (string) $request->validated('rate_date'),
            (float) $request->validated('rate'),
            (int) $request->user()->id,
        );

        return response()->json(['data' => $rate], 201);
    }
}
