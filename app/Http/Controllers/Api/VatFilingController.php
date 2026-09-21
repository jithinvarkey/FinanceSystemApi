<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VatReturnResource;
use App\Models\VatReturn;
use App\Services\VatFilingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * F15 — VAT filing workflow: list filings, snapshot a draft, file with ZATCA,
 * and record the settlement payment.
 */
final class VatFilingController extends Controller
{
    public function __construct(private readonly VatFilingService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $returns = VatReturn::query()
            ->with('filedBy:id,name')
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => VatReturnResource::collection($returns)]);
    }

    public function show(VatReturn $vatReturn): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => new VatReturnResource($vatReturn->load('filedBy:id,name'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.manage');

        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $return = $this->service->createDraft(
            Carbon::parse($data['period_from']),
            Carbon::parse($data['period_to']),
            (int) $request->user()->id,
            $data['notes'] ?? null,
        );

        return response()->json(['data' => new VatReturnResource($return)], 201);
    }

    public function refresh(VatReturn $vatReturn): JsonResponse
    {
        $this->authorize('general-ledger.manage');

        return response()->json(['data' => new VatReturnResource($this->service->refreshDraft($vatReturn))]);
    }

    public function file(Request $request, VatReturn $vatReturn): JsonResponse
    {
        $this->authorize('general-ledger.manage');

        $data = $request->validate(['zatca_reference' => ['nullable', 'string', 'max:100']]);

        $return = $this->service->file($vatReturn, (int) $request->user()->id, $data['zatca_reference'] ?? null);

        return response()->json(['data' => new VatReturnResource($return)]);
    }

    public function pay(Request $request, VatReturn $vatReturn): JsonResponse
    {
        $this->authorize('general-ledger.post');

        $data = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'paid_on' => ['nullable', 'date'],
        ]);

        $return = $this->service->recordPayment(
            $vatReturn,
            (int) $data['bank_account_id'],
            (int) $request->user()->id,
            isset($data['paid_on']) ? Carbon::parse($data['paid_on']) : null,
        );

        return response()->json(['data' => new VatReturnResource($return)]);
    }

    public function destroy(VatReturn $vatReturn): JsonResponse
    {
        $this->authorize('general-ledger.manage');

        $this->service->deleteDraft($vatReturn);

        return response()->json(null, 204);
    }
}
