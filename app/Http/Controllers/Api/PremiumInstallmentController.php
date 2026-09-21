<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Policy;
use App\Models\PremiumInstallment;
use App\Models\PremiumInstallmentPlan;
use App\Services\PremiumInstallmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * N1 — Premium installment plans.
 */
final class PremiumInstallmentController extends Controller
{
    public function __construct(private readonly PremiumInstallmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('policies.view');

        $plans = PremiumInstallmentPlan::query()
            ->with(['items', 'policy:id,policy_number'])
            ->when($request->integer('policy_id'), fn ($q, $id) => $q->where('policy_id', $id))
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $plans]);
    }

    public function due(Request $request): JsonResponse
    {
        $this->authorize('policies.view');
        $asOf = $request->date('as_of') ?? Carbon::today();

        return response()->json(['data' => $this->service->dueReport(Carbon::parse($asOf->toDateString()), (int) $request->integer('horizon_days', 30))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('policies.manage');
        $data = $request->validate([
            'policy_id' => ['required', 'integer', 'exists:policies,id'],
            'installments' => ['required', 'integer', 'min:1', 'max:60'],
            'frequency' => ['required', 'in:monthly,quarterly,semi_annual'],
            'start_date' => ['required', 'date'],
            'total_amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $policy = Policy::query()->findOrFail($data['policy_id']);
        $plan = $this->service->createPlan(
            $policy, (int) $data['installments'], $data['frequency'], Carbon::parse($data['start_date']),
            isset($data['total_amount']) ? (float) $data['total_amount'] : null, (int) $request->user()->id,
        );

        return response()->json(['data' => $plan], 201);
    }

    public function recordPayment(Request $request, PremiumInstallment $premiumInstallment): JsonResponse
    {
        $this->authorize('policies.manage');
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);

        $updated = $this->service->recordPayment($premiumInstallment, (float) $data['amount'], (int) $request->user()->id);

        return response()->json(['data' => $updated]);
    }
}
