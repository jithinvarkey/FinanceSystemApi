<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeBenefit;
use App\Services\BenefitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * W2 — Employee benefits (entitlements, provision accrual, cost recording).
 */
final class BenefitController extends Controller
{
    public function __construct(private readonly BenefitService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $benefits = EmployeeBenefit::query()->with('employee:id,name,employee_code')->latest('id')->get()
            ->map(fn (EmployeeBenefit $b): array => [
                'id' => $b->id, 'benefit_type' => $b->benefit_type, 'description' => $b->description,
                'employee' => $b->employee?->name, 'annual_amount' => $b->annual_amount,
                'accrued_amount' => $b->accrued_amount, 'utilized_amount' => $b->utilized_amount,
                'outstanding_provision' => $b->outstandingProvision(), 'renewal_date' => $b->renewal_date?->toDateString(), 'status' => $b->status,
            ]);

        return response()->json(['data' => $benefits]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'payroll_employee_id' => ['required', 'integer', 'exists:payroll_employees,id'],
            'benefit_type' => ['required', 'in:air_ticket,medical,visa,iqama,other'],
            'description' => ['nullable', 'string', 'max:180'],
            'annual_amount' => ['required', 'numeric', 'min:0'],
            'renewal_date' => ['nullable', 'date'],
        ]);

        $benefit = EmployeeBenefit::query()->create([...$data, 'status' => 'active', 'created_by' => (int) $request->user()->id]);

        return response()->json(['data' => $benefit], 201);
    }

    public function accrue(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'date' => ['required', 'date'],
        ]);

        $result = $this->service->accrueMonth((int) $data['period_year'], (int) $data['period_month'], Carbon::parse($data['date']), (int) $request->user()->id);

        return response()->json(['data' => $result]);
    }

    public function recordCost(Request $request, EmployeeBenefit $employeeBenefit): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cost_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        $cost = $this->service->recordCost($employeeBenefit, (float) $data['amount'], Carbon::parse($data['cost_date']), $data['description'] ?? null, (int) $request->user()->id);

        return response()->json(['data' => $cost], 201);
    }
}
