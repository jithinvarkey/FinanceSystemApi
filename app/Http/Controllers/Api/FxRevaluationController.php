<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FxRevaluation;
use App\Services\FxRevaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * F22 — FX revaluation: preview and post the unrealized gain/loss on foreign-
 * currency monetary balances at a period end.
 */
final class FxRevaluationController extends Controller
{
    public function __construct(private readonly FxRevaluationService $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate(['as_of' => ['required', 'date']]);

        return response()->json(['data' => $this->service->preview(Carbon::parse($data['as_of']))]);
    }

    public function post(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'as_of' => ['required', 'date'],
            'fx_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
        ]);

        $run = $this->service->post(Carbon::parse($data['as_of']), (int) $data['fx_account_id'], (int) $request->user()->id);

        return response()->json(['data' => [
            'id' => $run->id,
            'as_of' => $run->as_of->toDateString(),
            'net_adjustment' => $run->net_adjustment,
            'batch_number' => $run->batch_number,
        ]], 201);
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => FxRevaluation::query()->latest('as_of')->latest('id')->limit(50)->get()]);
    }
}
