<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KpiTarget;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * N3 — KPI engine + role scorecards.
 */
final class KpiController extends Controller
{
    public function __construct(private readonly KpiService $service)
    {
    }

    public function library(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $this->service->library()]);
    }

    public function scorecard(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $audience = $request->string('audience')->toString() ?: 'cfo';

        return response()->json(['data' => $this->service->scorecard($audience)]);
    }

    public function updateTargets(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.kpi_key' => ['required', 'string'],
            'targets.*.target' => ['nullable', 'numeric'],
        ]);

        foreach ($data['targets'] as $t) {
            KpiTarget::query()->where('kpi_key', $t['kpi_key'])->update(['target' => $t['target'] ?? null]);
        }

        return response()->json(['data' => $this->service->library()]);
    }
}
