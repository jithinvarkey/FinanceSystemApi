<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Services\CommissionCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * E5 — Commission rule master + a resolve/preview endpoint.
 */
final class CommissionRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('finance-config.view');

        $rules = CommissionRule::query()->with('tiers')->latest('id')->get()->map(fn (CommissionRule $r): array => $this->present($r));

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $data = $this->validateRule($request);

        $rule = DB::transaction(function () use ($data, $request): CommissionRule {
            $rule = CommissionRule::query()->create([
                'name' => $data['name'], 'insurer_id' => $data['insurer_id'] ?? null, 'product_id' => $data['product_id'] ?? null,
                'rule_type' => $data['rule_type'], 'rate' => $data['rate'] ?? 0, 'is_active' => $data['is_active'] ?? true,
                'created_by' => (int) $request->user()->id,
            ]);
            $this->syncTiers($rule, $data);

            return $rule;
        });

        return response()->json(['data' => $this->present($rule->load('tiers'))], 201);
    }

    public function update(Request $request, CommissionRule $commissionRule): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $data = $this->validateRule($request);

        DB::transaction(function () use ($commissionRule, $data): void {
            $commissionRule->update([
                'name' => $data['name'], 'insurer_id' => $data['insurer_id'] ?? null, 'product_id' => $data['product_id'] ?? null,
                'rule_type' => $data['rule_type'], 'rate' => $data['rate'] ?? 0, 'is_active' => $data['is_active'] ?? $commissionRule->is_active,
            ]);
            $commissionRule->tiers()->delete();
            $this->syncTiers($commissionRule, $data);
        });

        return response()->json(['data' => $this->present($commissionRule->load('tiers'))]);
    }

    public function destroy(CommissionRule $commissionRule): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $commissionRule->delete();

        return response()->json(null, 204);
    }

    public function resolve(Request $request, CommissionCalculatorService $calc): JsonResponse
    {
        $this->authorize('finance-config.view');
        $data = $request->validate([
            'insurer_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'net_premium' => ['required', 'numeric', 'min:0'],
            'is_renewal' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $calc->resolve(
            $data['insurer_id'] ?? null, $data['product_id'] ?? null, (float) $data['net_premium'], (bool) ($data['is_renewal'] ?? false),
        )]);
    }

    /** @return array<string,mixed> */
    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'insurer_id' => ['nullable', Rule::exists('vendors', 'id')],
            'product_id' => ['nullable', Rule::exists('products', 'id')],
            'rule_type' => ['required', 'in:flat,tiered,renewal'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'tiers' => ['array'],
            'tiers.*.min_premium' => ['required_with:tiers', 'numeric', 'min:0'],
            'tiers.*.rate' => ['required_with:tiers', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /** @param array<string,mixed> $data */
    private function syncTiers(CommissionRule $rule, array $data): void
    {
        if (($data['rule_type'] ?? null) === 'tiered' && ! empty($data['tiers'])) {
            $rule->tiers()->createMany(array_map(fn (array $t): array => [
                'min_premium' => round((float) $t['min_premium'], 2), 'rate' => round((float) $t['rate'], 4),
            ], $data['tiers']));
        }
    }

    /** @return array<string,mixed> */
    private function present(CommissionRule $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'insurer_id' => $r->insurer_id, 'product_id' => $r->product_id,
            'rule_type' => $r->rule_type, 'rate' => $r->rate, 'is_active' => (bool) $r->is_active,
            'tiers' => $r->tiers->map(fn ($t): array => ['min_premium' => $t->min_premium, 'rate' => $t->rate])->values(),
        ];
    }
}
