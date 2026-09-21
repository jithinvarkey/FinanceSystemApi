<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Models\PayrollEmployee;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * W3 — Employee loans & salary advances.
 */
final class LoanController extends Controller
{
    public function __construct(private readonly LoanService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $loans = EmployeeLoan::query()->with('employee:id,name,employee_code')->latest('id')->get()
            ->map(fn (EmployeeLoan $l): array => [
                'id' => $l->id, 'reference' => $l->reference, 'employee' => $l->employee?->name, 'loan_type' => $l->loan_type,
                'principal' => $l->principal, 'monthly_installment' => $l->monthly_installment,
                'outstanding_balance' => $l->outstanding_balance, 'disbursed_date' => $l->disbursed_date?->toDateString(), 'status' => $l->status,
            ]);

        return response()->json(['data' => $loans]);
    }

    public function disburse(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'payroll_employee_id' => ['required', 'integer', 'exists:payroll_employees,id'],
            'loan_type' => ['required', 'in:loan,advance'],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'monthly_installment' => ['required', 'numeric', 'min:0.01'],
            'disbursed_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        $employee = PayrollEmployee::query()->findOrFail($data['payroll_employee_id']);
        $loan = $this->service->disburse(
            $employee, $data['loan_type'], (float) $data['principal'], (float) $data['monthly_installment'],
            Carbon::parse($data['disbursed_date']), $data['notes'] ?? null, (int) $request->user()->id,
        );

        return response()->json(['data' => $loan], 201);
    }
}
