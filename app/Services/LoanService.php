<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\EmployeeLoan;
use App\Models\LoanRecovery;
use App\Models\PayrollEmployee;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * W3 — Employee loans & salary advances. Disbursement posts Dr loan receivable /
 * Cr bank; recovery is driven by the payroll run (this service plans the
 * deduction and, on posting, reduces the loan balance).
 */
final class LoanService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    public function disburse(PayrollEmployee $employee, string $type, float $principal, float $installment, Carbon $date, ?string $notes, int $userId): EmployeeLoan
    {
        $principal = round($principal, 2);
        if ($principal <= 0 || $installment <= 0) {
            throw new FinanceRuleException('Principal and instalment must be positive.');
        }
        $s = PayrollSetting::current();
        $recvId = $this->required($s->loan_receivable_account_id, 'loan receivable');
        $bankId = $this->required($s->loan_bank_account_id, 'loan disbursement bank');

        return DB::transaction(function () use ($employee, $type, $principal, $installment, $date, $notes, $userId, $recvId, $bankId): EmployeeLoan {
            $loan = EmployeeLoan::query()->create([
                'reference' => $this->numbers->next('employee_loan', $date),
                'payroll_employee_id' => $employee->id, 'loan_type' => $type, 'principal' => $principal,
                'monthly_installment' => round($installment, 2), 'outstanding_balance' => $principal,
                'disbursed_date' => $date->toDateString(), 'status' => 'active', 'notes' => $notes, 'created_by' => $userId,
            ]);

            $rows = $this->posting->post($loan, $date, [
                new PostingLine($recvId, $principal, 0.0, 'SAR', 1.0, null, 'Loan disbursement — '.$employee->name),
                new PostingLine($bankId, 0.0, $principal, 'SAR', 1.0, null, 'Loan disbursement — '.$employee->name),
            ], $userId);
            $loan->update(['batch_number' => $rows->first()->batch_number]);

            return $loan;
        });
    }

    /**
     * Plan recoveries for an employee on a run; returns the total deducted and
     * records pending LoanRecovery rows.
     */
    public function planRecoveries(PayrollRun $run, PayrollEmployee $employee): float
    {
        $loans = EmployeeLoan::query()->where('payroll_employee_id', $employee->id)->where('status', 'active')->where('outstanding_balance', '>', 0)->get();

        $total = 0.0;
        foreach ($loans as $loan) {
            $amount = $loan->plannedInstallment();
            if ($amount <= 0) {
                continue;
            }
            LoanRecovery::query()->create(['payroll_run_id' => $run->id, 'employee_loan_id' => $loan->id, 'amount' => $amount, 'applied' => false]);
            $total = round($total + $amount, 2);
        }

        return $total;
    }

    /** Apply this run's recoveries (called on payroll post): reduce each loan. */
    public function applyRecoveries(PayrollRun $run): void
    {
        $recoveries = LoanRecovery::query()->where('payroll_run_id', $run->id)->where('applied', false)->with('loan')->get();
        foreach ($recoveries as $rec) {
            $loan = $rec->loan;
            if ($loan === null) {
                continue;
            }
            $newBalance = round((float) $loan->outstanding_balance - (float) $rec->amount, 2);
            $loan->update(['outstanding_balance' => max($newBalance, 0), 'status' => $newBalance <= 0.01 ? 'settled' : 'active']);
            $rec->update(['applied' => true]);
        }
    }

    /** Total loan recovery planned on a run (for the GL credit to loan receivable). */
    public function plannedTotal(PayrollRun $run): float
    {
        return round((float) LoanRecovery::query()->where('payroll_run_id', $run->id)->sum('amount'), 2);
    }

    public function loanReceivableAccountId(): int
    {
        return $this->required(PayrollSetting::current()->loan_receivable_account_id, 'loan receivable');
    }

    /** @return Collection<int, EmployeeLoan> */
    public function activeFor(int $employeeId): Collection
    {
        return EmployeeLoan::query()->where('payroll_employee_id', $employeeId)->where('status', 'active')->get();
    }

    private function required(?int $id, string $label): int
    {
        if ($id === null) {
            throw new FinanceRuleException("Configure the {$label} account in payroll settings first.");
        }

        return $this->resolvePostable($id);
    }
}
