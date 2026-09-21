<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportDefinition;
use App\Services\ReportBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * N4 — Self-service report builder (saved definitions + run + drill-through).
 */
final class ReportBuilderController extends Controller
{
    public function __construct(private readonly ReportBuilderService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => ReportDefinition::query()->latest('id')->limit(100)->get()]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate([
            'measure' => ['required', 'in:net,debit,credit'],
            'group_by' => ['required', 'array', 'min:1'],
            'group_by.*' => ['string', 'in:'.implode(',', ReportBuilderService::DIMENSIONS)],
            'filters' => ['nullable', 'array'],
            'filters.from' => ['nullable', 'date'],
            'filters.to' => ['nullable', 'date'],
            'filters.account_type' => ['nullable', 'in:asset,liability,equity,revenue,expense'],
        ]);

        return response()->json(['data' => $this->service->run($data['measure'], $data['group_by'], $data['filters'] ?? [])]);
    }

    public function drill(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1'],
            'filters' => ['nullable', 'array'],
        ]);

        return response()->json(['data' => $this->service->drillThrough($data['keys'], $data['filters'] ?? [])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:250'],
            'measure' => ['required', 'in:net,debit,credit'],
            'group_by' => ['required', 'array', 'min:1'],
            'filters' => ['nullable', 'array'],
        ]);

        $def = ReportDefinition::query()->create([
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'measure' => $data['measure'],
            'group_by' => implode(',', $data['group_by']), 'filters' => $data['filters'] ?? null,
            'is_shared' => true, 'created_by' => (int) $request->user()->id,
        ]);

        return response()->json(['data' => $def], 201);
    }

    public function destroy(ReportDefinition $reportDefinition): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $reportDefinition->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
