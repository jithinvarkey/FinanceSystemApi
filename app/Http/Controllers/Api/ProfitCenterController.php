<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProfitCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * N5 — Profit-center P&L (profit per cost centre).
 */
final class ProfitCenterController extends Controller
{
    public function __construct(private readonly ProfitCenterService $service)
    {
    }

    public function statement(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->service->statement(
            isset($data['from']) ? Carbon::parse($data['from']) : null,
            isset($data['to']) ? Carbon::parse($data['to']) : null,
        )]);
    }
}
