<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Vendor;
use App\Services\BrokerReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * P4.17 Slice D (§12) — Broker bordereaux reports: per-insurer net position and
 * the per-insurer policy statement. Read-only; gated on policies.view.
 */
final class BrokerReportController extends Controller
{
    public function __construct(private readonly BrokerReportService $service)
    {
    }

    public function insurerPositions(): JsonResponse
    {
        $this->authorize('policies.view');

        return response()->json(['data' => $this->service->insurerPositions()]);
    }

    public function insurerStatement(Vendor $insurer): JsonResponse
    {
        $this->authorize('policies.view');

        return response()->json(['data' => $this->service->insurerStatement($insurer)]);
    }

    public function customerStatement(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('policies.view');

        $request->validate(['policy_id' => ['nullable', 'integer']]);
        $policyId = $request->filled('policy_id') ? (int) $request->integer('policy_id') : null;

        return response()->json(['data' => $this->service->customerStatement($customer, $policyId)]);
    }

    public function customerAging(Request $request): JsonResponse
    {
        $this->authorize('policies.view');

        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return response()->json(['data' => $this->service->customerAging($asOf)]);
    }

    public function insurerAging(Request $request): JsonResponse
    {
        $this->authorize('policies.view');

        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return response()->json(['data' => $this->service->insurerAging($asOf)]);
    }
}
