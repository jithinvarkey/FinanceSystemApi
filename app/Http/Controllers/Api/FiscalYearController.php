<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFiscalYearRequest;
use App\Http\Resources\FiscalPeriodResource;
use App\Http\Resources\FiscalYearResource;
use App\Repositories\Contracts\FiscalYearRepositoryInterface;
use App\Services\FiscalYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FIN-0002 / FIN-0040 — Fiscal years, periods, and period close.
 */
final class FiscalYearController extends Controller
{
    public function __construct(
        private readonly FiscalYearService $service,
        private readonly FiscalYearRepositoryInterface $fiscalYears,
    ) {
    }

    /**
     * GET /api/v1/finance-config/fiscal-years
     */
    public function index(): AnonymousResourceCollection
    {
        return FiscalYearResource::collection($this->fiscalYears->all(with: ['periods']));
    }

    /**
     * POST /api/v1/finance-config/fiscal-years — creates year + monthly periods.
     */
    public function store(StoreFiscalYearRequest $request): JsonResponse
    {
        $year = $this->service->createWithPeriods($request->validated(), (int) $request->user()->id);

        return (new FiscalYearResource($year))->response()->setStatusCode(201);
    }

    /**
     * POST /api/v1/finance-config/fiscal-periods/{period}/close
     */
    public function closePeriod(Request $request, int $period): FiscalPeriodResource
    {
        return new FiscalPeriodResource(
            $this->service->closePeriod($period, (int) $request->user()->id),
        );
    }

    /**
     * POST /api/v1/finance-config/fiscal-periods/{period}/reopen
     */
    public function reopenPeriod(int $period): FiscalPeriodResource
    {
        return new FiscalPeriodResource($this->service->reopenPeriod($period));
    }
}
