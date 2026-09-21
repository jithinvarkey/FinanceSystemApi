<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllocationRule;
use App\Services\AllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * F26 — Allocation journal rules: CRUD + run.
 */
final class AllocationController extends Controller
{
    public function __construct(private readonly AllocationService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $rules = AllocationRule::query()->with('lines')->latest('id')->get()->map(fn (AllocationRule $r): array => $this->present($r));

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.manage');

        $rule = $this->service->createRule($this->validateRule($request), (int) $request->user()->id);

        return response()->json(['data' => $this->present($rule)], 201);
    }

    public function update(Request $request, AllocationRule $allocationRule): JsonResponse
    {
        $this->authorize('general-ledger.manage');

        $rule = $this->service->updateRule($allocationRule, $this->validateRule($request));

        return response()->json(['data' => $this->present($rule)]);
    }

    public function destroy(AllocationRule $allocationRule): JsonResponse
    {
        $this->authorize('general-ledger.manage');
        $allocationRule->delete();

        return response()->json(null, 204);
    }

    public function run(Request $request, AllocationRule $allocationRule): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
        ]);

        $batch = $this->service->run($allocationRule, (float) $data['amount'], Carbon::parse($data['date']), (int) $request->user()->id);

        return response()->json(['data' => ['batch_number' => $batch]], 201);
    }

    /** @return array<string,mixed> */
    private function validateRule(Request $request): array
    {
        $coa = fn () => Rule::exists('chart_of_accounts', 'id')->where('is_postable', true);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'source_account_id' => ['required', $coa()],
            'target_account_id' => ['required', $coa()],
            'source_cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.cost_center_id' => ['required', Rule::exists('cost_centers', 'id')],
            'lines.*.percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /** @return array<string,mixed> */
    private function present(AllocationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'source_account_id' => $rule->source_account_id,
            'target_account_id' => $rule->target_account_id,
            'source_cost_center_id' => $rule->source_cost_center_id,
            'is_active' => (bool) $rule->is_active,
            'lines' => $rule->lines->map(fn ($l): array => [
                'cost_center_id' => $l->cost_center_id,
                'percentage' => $l->percentage,
            ])->values(),
        ];
    }
}
