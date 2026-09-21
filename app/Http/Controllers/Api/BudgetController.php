<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P8 — Budgeting. Reuses the GL permission set (view / manage).
 */
final class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $service)
    {
    }

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        return BudgetResource::collection(Budget::query()->with('fiscalYear')->latest('id')->get());
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = $this->service->save($request->validated(), (int) $request->user()->id);

        return (new BudgetResource($budget->load('fiscalYear')))->response()->setStatusCode(201);
    }

    public function show(Budget $budget): BudgetResource
    {
        $this->authorize('general-ledger.view');

        return new BudgetResource($budget->load(['lines.account', 'fiscalYear']));
    }

    public function update(StoreBudgetRequest $request, Budget $budget): BudgetResource
    {
        return new BudgetResource($this->service->save($request->validated(), (int) $request->user()->id, $budget)->load('fiscalYear'));
    }

    /** Budget vs GL actuals for the fiscal year. */
    public function vsActual(Budget $budget): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $this->service->vsActual($budget)]);
    }
}
