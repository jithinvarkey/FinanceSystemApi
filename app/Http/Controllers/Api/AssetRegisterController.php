<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Services\AssetRegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * E10 — Fixed asset transfers & maintenance log.
 */
final class AssetRegisterController extends Controller
{
    public function __construct(private readonly AssetRegisterService $service)
    {
    }

    public function history(FixedAsset $fixedAsset): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $this->service->history($fixedAsset)]);
    }

    public function transfer(Request $request, FixedAsset $fixedAsset): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'transfer_date' => ['required', 'date'],
            'to_cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'to_location' => ['nullable', 'string', 'max:120'],
            'to_custodian' => ['nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $row = $this->service->transfer(
            $fixedAsset, isset($data['to_cost_center_id']) ? (int) $data['to_cost_center_id'] : null,
            $data['to_location'] ?? null, $data['to_custodian'] ?? null,
            Carbon::parse($data['transfer_date']), $data['reason'], (int) $request->user()->id,
        );

        return response()->json(['data' => $row], 201);
    }

    public function maintenance(Request $request, FixedAsset $fixedAsset): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'maintenance_date' => ['required', 'date'],
            'type' => ['required', 'in:preventive,corrective,inspection'],
            'description' => ['required', 'string', 'max:250'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'vendor' => ['nullable', 'string', 'max:120'],
        ]);

        $row = $this->service->logMaintenance(
            $fixedAsset, Carbon::parse($data['maintenance_date']), $data['type'], $data['description'],
            (float) ($data['cost'] ?? 0), $data['vendor'] ?? null, (int) $request->user()->id,
        );

        return response()->json(['data' => $row], 201);
    }
}
