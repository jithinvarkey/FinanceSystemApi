<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EclRate;
use App\Services\EclProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * E14 — IFRS 9 expected credit loss provision matrix.
 */
final class EclController extends Controller
{
    public function __construct(private readonly EclProvisionService $service)
    {
    }

    public function rates(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => EclRate::query()->orderBy('id')->get()]);
    }

    public function updateRates(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.bucket' => ['required', 'string'],
            'rates.*.loss_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        foreach ($data['rates'] as $r) {
            EclRate::query()->where('bucket', $r['bucket'])->update(['loss_rate' => $r['loss_rate']]);
        }

        return response()->json(['data' => EclRate::query()->orderBy('id')->get()]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $asOf = $request->date('as_of') ?? Carbon::today();

        return response()->json(['data' => $this->service->matrix(Carbon::parse($asOf->toDateString()))]);
    }

    public function post(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'as_of' => ['required', 'date'],
            'expense_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'allowance_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
        ]);

        $result = $this->service->post(Carbon::parse($data['as_of']), (int) $data['expense_account_id'], (int) $data['allowance_account_id'], (int) $request->user()->id);

        return response()->json(['data' => $result], $result['posted'] ? 201 : 200);
    }
}
