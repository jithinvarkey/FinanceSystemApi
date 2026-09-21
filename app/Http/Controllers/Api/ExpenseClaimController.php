<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseClaimRequest;
use App\Http\Resources\ExpenseClaimResource;
use App\Models\ExpenseClaim;
use App\Services\ExpenseClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P6 — Expense claims. Reuses the AP permission set (view/manage/approve/post).
 */
final class ExpenseClaimController extends Controller
{
    public function __construct(private readonly ExpenseClaimService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        $claims = ExpenseClaim::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('claim_number', 'like', '%'.$request->string('search').'%')
                ->orWhere('claimant', 'like', '%'.$request->string('search').'%')))
            ->latest('id')
            ->limit(200)
            ->get();

        return ExpenseClaimResource::collection($claims);
    }

    public function store(StoreExpenseClaimRequest $request): JsonResponse
    {
        $claim = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new ExpenseClaimResource($claim))->response()->setStatusCode(201);
    }

    public function show(ExpenseClaim $expenseClaim): ExpenseClaimResource
    {
        $this->authorize('accounts-payable.view');

        return new ExpenseClaimResource($expenseClaim->load(['lines.account', 'creditAccount']));
    }

    public function update(StoreExpenseClaimRequest $request, ExpenseClaim $expenseClaim): ExpenseClaimResource
    {
        return new ExpenseClaimResource($this->service->updateDraft($expenseClaim, $request->validated())->load('creditAccount'));
    }

    public function destroy(Request $request, ExpenseClaim $expenseClaim): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $this->service->deleteDraft($expenseClaim);

        return response()->json(status: 204);
    }

    public function submit(Request $request, ExpenseClaim $expenseClaim): ExpenseClaimResource
    {
        $this->authorize('accounts-payable.manage');

        return new ExpenseClaimResource($this->service->submit($expenseClaim, (int) $request->user()->id)->load('creditAccount'));
    }

    public function post(Request $request, ExpenseClaim $expenseClaim): ExpenseClaimResource
    {
        $this->authorize('accounts-payable.post');

        return new ExpenseClaimResource($this->service->post($expenseClaim, (int) $request->user()->id)->load('creditAccount'));
    }
}
