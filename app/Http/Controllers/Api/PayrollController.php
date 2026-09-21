<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollEmployee;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * W1 — Payroll: settings, employee master, and run workflow.
 */
final class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service)
    {
    }

    // --- Settings ---

    public function settings(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => PayrollSetting::current()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $acct = ['nullable', 'integer', 'exists:chart_of_accounts,id'];
        $data = $request->validate([
            'basic_salary_account_id' => $acct, 'housing_account_id' => $acct, 'transport_account_id' => $acct,
            'other_earnings_account_id' => $acct, 'gosi_expense_account_id' => $acct, 'gosi_payable_account_id' => $acct,
            'eosb_expense_account_id' => $acct, 'eosb_provision_account_id' => $acct, 'net_payable_account_id' => $acct,
            'airticket_expense_account_id' => $acct, 'airticket_provision_account_id' => $acct,
            'benefit_expense_account_id' => $acct, 'benefit_payable_account_id' => $acct,
            'loan_receivable_account_id' => $acct, 'loan_bank_account_id' => $acct,
            'gosi_employee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gosi_employer_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gosi_expat_employer_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'eosb_days_per_year' => ['nullable', 'numeric', 'min:0', 'max:60'],
        ]);

        $settings = PayrollSetting::current();
        $settings->update($data);

        return response()->json(['data' => $settings->fresh()]);
    }

    // --- Employees ---

    public function employees(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => PayrollEmployee::query()->orderBy('name')->get()]);
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $this->validateEmployee($request);
        $data['employee_code'] = strtoupper($data['employee_code']);

        $emp = PayrollEmployee::query()->create([...$data, 'status' => 'active', 'created_by' => (int) $request->user()->id]);

        return response()->json(['data' => $emp], 201);
    }

    public function updateEmployee(Request $request, PayrollEmployee $payrollEmployee): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $this->validateEmployee($request, $payrollEmployee->id);
        $payrollEmployee->update($data);

        return response()->json(['data' => $payrollEmployee->fresh()]);
    }

    // --- Runs ---

    public function runs(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => PayrollRun::query()->latest('id')->limit(60)->get()]);
    }

    public function showRun(PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $payrollRun->load('lines')]);
    }

    public function storeRun(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'pay_date' => ['required', 'date'],
        ]);

        $run = $this->service->createRun((int) $data['period_year'], (int) $data['period_month'], Carbon::parse($data['pay_date']), (int) $request->user()->id);

        return response()->json(['data' => $run], 201);
    }

    public function storeOffCycle(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'pay_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.payroll_employee_id' => ['required', 'integer', 'exists:payroll_employees,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $run = $this->service->createOffCycle((int) $data['period_year'], (int) $data['period_month'], Carbon::parse($data['pay_date']), $data['items'], (int) $request->user()->id);

        return response()->json(['data' => $run], 201);
    }

    public function finalSettlement(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'payroll_employee_id' => ['required', 'integer', 'exists:payroll_employees,id'],
            'last_day' => ['required', 'date'],
            'pay_date' => ['required', 'date'],
        ]);

        $employee = PayrollEmployee::query()->findOrFail($data['payroll_employee_id']);
        $run = $this->service->finalSettlement($employee, Carbon::parse($data['last_day']), Carbon::parse($data['pay_date']), (int) $request->user()->id);

        return response()->json(['data' => $run->load('lines')], 201);
    }

    public function bankFile(PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $this->service->bankFile($payrollRun->load('lines'))]);
    }

    public function approveRun(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('general-ledger.approve');

        return response()->json(['data' => $this->service->approve($payrollRun, (int) $request->user()->id)]);
    }

    public function postRun(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('general-ledger.post');

        return response()->json(['data' => $this->service->post($payrollRun, (int) $request->user()->id)]);
    }

    /** @return array<string, mixed> */
    private function validateEmployee(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'employee_code' => ['required', 'string', 'max:30', 'unique:payroll_employees,employee_code'.($ignoreId ? ','.$ignoreId : '')],
            'name' => ['required', 'string', 'max:150'],
            'is_saudi' => ['required', 'boolean'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowance' => ['nullable', 'numeric', 'min:0'],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'salary_grade_id' => ['nullable', 'integer', 'exists:salary_grades,id'],
            'join_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'iban' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
    }
}
