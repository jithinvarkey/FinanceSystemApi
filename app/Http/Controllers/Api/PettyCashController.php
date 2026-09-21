<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePettyCashRequest;
use App\Http\Resources\PettyCashVoucherResource;
use App\Models\PettyCashVoucher;
use App\Services\PettyCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P6 — Petty cash vouchers. Reuses the AP permission set.
 */
final class PettyCashController extends Controller
{
    public function __construct(private readonly PettyCashService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        $vouchers = PettyCashVoucher::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('voucher_number', 'like', '%'.$request->string('search').'%')
                ->orWhere('payee', 'like', '%'.$request->string('search').'%')))
            ->latest('id')->limit(200)->get();

        return PettyCashVoucherResource::collection($vouchers);
    }

    public function store(StorePettyCashRequest $request): JsonResponse
    {
        $voucher = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new PettyCashVoucherResource($voucher))->response()->setStatusCode(201);
    }

    public function show(PettyCashVoucher $pettyCashVoucher): PettyCashVoucherResource
    {
        $this->authorize('accounts-payable.view');

        return new PettyCashVoucherResource($pettyCashVoucher->load(['pettyCashAccount', 'expenseAccount']));
    }

    public function update(StorePettyCashRequest $request, PettyCashVoucher $pettyCashVoucher): PettyCashVoucherResource
    {
        return new PettyCashVoucherResource($this->service->updateDraft($pettyCashVoucher, $request->validated()));
    }

    public function destroy(Request $request, PettyCashVoucher $pettyCashVoucher): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $this->service->deleteDraft($pettyCashVoucher);

        return response()->json(status: 204);
    }

    public function post(Request $request, PettyCashVoucher $pettyCashVoucher): PettyCashVoucherResource
    {
        $this->authorize('accounts-payable.post');

        return new PettyCashVoucherResource($this->service->post($pettyCashVoucher, (int) $request->user()->id)
            ->load(['pettyCashAccount', 'expenseAccount']));
    }
}
