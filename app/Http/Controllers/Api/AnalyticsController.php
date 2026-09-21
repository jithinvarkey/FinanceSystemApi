<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsCubeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * E8 — BI analytics: pivot cube + what-if projection.
 */
final class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsCubeService $service)
    {
    }

    public function cube(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate([
            'measure' => ['required', 'in:net,debit,credit'],
            'row' => ['required', 'in:month,account_type,cost_center,dimension'],
            'col' => ['nullable', 'in:month,account_type,cost_center,dimension'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->service->pivot(
            $data['measure'], $data['row'], $data['col'] ?? null,
            isset($data['from']) ? Carbon::parse($data['from']) : null,
            isset($data['to']) ? Carbon::parse($data['to']) : null,
        )]);
    }

    public function whatIf(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate([
            'account_type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'growth_pct' => ['required', 'numeric', 'between:-100,1000'],
            'adjustment' => ['nullable', 'numeric'],
            'months' => ['required', 'integer', 'between:1,24'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->service->whatIf(
            $data['account_type'], (float) $data['growth_pct'], (float) ($data['adjustment'] ?? 0), (int) $data['months'],
            isset($data['from']) ? Carbon::parse($data['from']) : null,
            isset($data['to']) ? Carbon::parse($data['to']) : null,
        )]);
    }
}
