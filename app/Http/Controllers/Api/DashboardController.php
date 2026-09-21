<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard summaries. The Accountant dashboard is the daily work cockpit.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service)
    {
    }

    public function accountant(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->accountantSummary($request->user()),
        ]);
    }

    public function executive(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: null;

        return response()->json([
            'data' => $this->service->executiveSummary($request->user(), $year),
        ]);
    }

    /** E1 — Executive Command Center: forecast + analytical widgets. */
    public function commandCenter(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $year = $request->integer('year') ?: null;

        return response()->json(['data' => $this->service->commandCenter($year)]);
    }
}
