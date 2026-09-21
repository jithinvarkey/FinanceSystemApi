<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\PayrollEmployee;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
// LoanService is resolved from the container (W3 payroll recovery).
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W1 — Payroll finance core. Builds a monthly payroll run (earnings, GOSI, EOSB
 * accrual, net pay per employee) and posts a single balanced journal:
 *   Dr salary/GOSI/EOSB expenses  Cr GOSI payable + EOSB provision + net payable.
 */
final class PayrollService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
        private readonly LoanService $loans,
    ) {
    }

    /** Build a draft run for a period, snapshotting every active employee. */
    public function createRun(int $year, int $month, Carbon $payDate, int $userId): PayrollRun
    {
        if ($month < 1 || $month > 12) {
            throw new FinanceRuleException('Month must be 1–12.');
        }
        if (PayrollRun::query()->where('run_type', 'regular')->where('period_year', $year)->where('period_month', $month)->exists()) {
            throw new FinanceRuleException('A regular payroll run already exists for this period.');
        }

        $settings = PayrollSetting::current();
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();

        $employees = PayrollEmployee::query()
            ->where('status', 'active')
            ->whereDate('join_date', '<=', $periodEnd->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', Carbon::create($year, $month, 1)->toDateString()))
            ->get();

        if ($employees->isEmpty()) {
            throw new FinanceRuleException('No active employees to run payroll for.');
        }

        return DB::transaction(function () use ($year, $month, $payDate, $userId, $settings, $employees): PayrollRun {
            $run = PayrollRun::query()->create([
                'reference' => $this->numbers->next('payroll_run', $payDate), 'run_type' => 'regular',
                'period_year' => $year, 'period_month' => $month, 'pay_date' => $payDate->toDateString(),
                'status' => 'draft', 'created_by' => $userId,
            ]);

            $totals = ['gross' => 0.0, 'gosi_emp' => 0.0, 'gosi_empr' => 0.0, 'eosb' => 0.0, 'net' => 0.0];

            foreach ($employees as $emp) {
                $gross = $emp->grossSalary();
                $gosiBase = $emp->gosiBase();
                $gosiEmp = $emp->is_saudi ? round($gosiBase * (float) $settings->gosi_employee_rate / 100, 2) : 0.0;
                $gosiEmpr = round($gosiBase * (float) ($emp->is_saudi ? $settings->gosi_employer_rate : $settings->gosi_expat_employer_rate) / 100, 2);
                // Monthly EOSB accrual on basic: (basic/30 × days_per_year) / 12.
                $eosb = round(((float) $emp->basic_salary / 30 * (float) $settings->eosb_days_per_year) / 12, 2);
                // W3 — recover this month's loan instalments (planned; applied on posting).
                $loanRecovery = $this->loans->planRecoveries($run, $emp);
                $net = round($gross - $gosiEmp - $loanRecovery, 2);

                $run->lines()->create([
                    'payroll_employee_id' => $emp->id, 'employee_name' => $emp->name,
                    'basic' => $emp->basic_salary, 'housing' => $emp->housing_allowance,
                    'transport' => $emp->transport_allowance, 'other_earnings' => $emp->other_allowance,
                    'gross' => $gross, 'gosi_employee' => $gosiEmp, 'gosi_employer' => $gosiEmpr,
                    'eosb_accrual' => $eosb, 'other_deductions' => $loanRecovery, 'net_pay' => $net,
                ]);

                $totals['gross'] = round($totals['gross'] + $gross, 2);
                $totals['gosi_emp'] = round($totals['gosi_emp'] + $gosiEmp, 2);
                $totals['gosi_empr'] = round($totals['gosi_empr'] + $gosiEmpr, 2);
                $totals['eosb'] = round($totals['eosb'] + $eosb, 2);
                $totals['net'] = round($totals['net'] + $net, 2);
            }

            $run->update([
                'gross_total' => $totals['gross'], 'gosi_employee_total' => $totals['gosi_emp'],
                'gosi_employer_total' => $totals['gosi_empr'], 'eosb_total' => $totals['eosb'], 'net_total' => $totals['net'],
            ]);

            return $run->load('lines');
        });
    }

    /**
     * Off-cycle / bonus run for selected employees (ad-hoc amounts, no GOSI/EOSB).
     * Reuses the standard post() — the amount flows through "other earnings".
     *
     * @param  list<array{payroll_employee_id: int, amount: float}>  $items
     */
    public function createOffCycle(int $year, int $month, Carbon $payDate, array $items, int $userId): PayrollRun
    {
        $items = array_values(array_filter($items, fn (array $i): bool => (float) $i['amount'] > 0));
        if ($items === []) {
            throw new FinanceRuleException('An off-cycle run needs at least one positive amount.');
        }

        return DB::transaction(function () use ($year, $month, $payDate, $items, $userId): PayrollRun {
            $run = PayrollRun::query()->create([
                'reference' => $this->numbers->next('payroll_run', $payDate), 'run_type' => 'off_cycle',
                'period_year' => $year, 'period_month' => $month, 'pay_date' => $payDate->toDateString(),
                'status' => 'draft', 'created_by' => $userId,
            ]);

            $total = 0.0;
            foreach ($items as $item) {
                $emp = PayrollEmployee::query()->findOrFail($item['payroll_employee_id']);
                $amount = round((float) $item['amount'], 2);
                $run->lines()->create([
                    'payroll_employee_id' => $emp->id, 'employee_name' => $emp->name,
                    'basic' => 0, 'housing' => 0, 'transport' => 0, 'other_earnings' => $amount,
                    'gross' => $amount, 'gosi_employee' => 0, 'gosi_employer' => 0, 'eosb_accrual' => 0, 'other_deductions' => 0, 'net_pay' => $amount,
                ]);
                $total = round($total + $amount, 2);
            }
            $run->update(['gross_total' => $total, 'net_total' => $total]);

            return $run->load('lines');
        });
    }

    /**
     * Final settlement for a leaving employee: EOSB gratuity by service years,
     * net of outstanding loans, posted Dr EOSB provision / Cr net payable +
     * Cr loan receivable. Marks the employee inactive.
     */
    public function finalSettlement(PayrollEmployee $employee, Carbon $lastDay, Carbon $payDate, int $userId): PayrollRun
    {
        if ($employee->status !== 'active') {
            throw new FinanceRuleException('This employee is not active.');
        }
        $s = PayrollSetting::current();
        $provId = $this->resolveAccount($s->eosb_provision_account_id, 'EOSB provision');
        $netId = $this->resolveAccount($s->net_payable_account_id, 'net payable');

        $gratuity = $this->eosbGratuity($employee, $lastDay);
        $outstanding = round((float) $this->loans->activeFor($employee->id)->sum('outstanding_balance'), 2);
        $loanRecovery = round(min($outstanding, $gratuity), 2);
        $net = round($gratuity - $loanRecovery, 2);

        return DB::transaction(function () use ($employee, $lastDay, $payDate, $userId, $provId, $netId, $gratuity, $loanRecovery, $net): PayrollRun {
            $run = PayrollRun::query()->create([
                'reference' => $this->numbers->next('payroll_run', $payDate), 'run_type' => 'final_settlement',
                'payroll_employee_id' => $employee->id, 'period_year' => $payDate->year, 'period_month' => $payDate->month,
                'pay_date' => $payDate->toDateString(), 'eosb_total' => $gratuity, 'net_total' => $net, 'status' => 'approved', 'created_by' => $userId, 'approved_by' => $userId,
            ]);
            $run->lines()->create([
                'payroll_employee_id' => $employee->id, 'employee_name' => $employee->name,
                'basic' => 0, 'housing' => 0, 'transport' => 0, 'other_earnings' => 0, 'gross' => 0,
                'gosi_employee' => 0, 'gosi_employer' => 0, 'eosb_accrual' => $gratuity, 'other_deductions' => $loanRecovery, 'net_pay' => $net,
            ]);

            $lines = [new PostingLine($provId, $gratuity, 0.0, 'SAR', 1.0, null, 'EOSB gratuity — '.$employee->name)];
            if ($loanRecovery > 0) {
                $lines[] = new PostingLine($this->loans->loanReceivableAccountId(), 0.0, $loanRecovery, 'SAR', 1.0, null, 'Loan settlement — '.$employee->name);
            }
            if ($net > 0) {
                $lines[] = new PostingLine($netId, 0.0, $net, 'SAR', 1.0, null, 'Final settlement payable — '.$employee->name);
            }
            $rows = $this->posting->post($run, $payDate, $lines, $userId);

            // Settle the employee's loans and deactivate them.
            foreach ($this->loans->activeFor($employee->id) as $loan) {
                $loan->update(['outstanding_balance' => 0, 'status' => 'settled']);
            }
            $employee->update(['status' => 'inactive', 'end_date' => $lastDay->toDateString()]);
            $run->update(['status' => 'posted', 'batch_number' => $rows->first()->batch_number]);

            return $run->fresh('lines');
        });
    }

    /** Saudi EOSB: ½-month wage/yr for the first 5 years, full month/yr after. */
    private function eosbGratuity(PayrollEmployee $employee, Carbon $lastDay): float
    {
        $wage = round((float) $employee->basic_salary + (float) $employee->housing_allowance, 2);
        $years = max(0, (float) Carbon::parse((string) $employee->join_date)->floatDiffInYears($lastDay));
        $first = min($years, 5);
        $beyond = max($years - 5, 0);

        return round($wage * (0.5 * $first + 1.0 * $beyond), 2);
    }

    private function resolveAccount(?int $id, string $label): int
    {
        if ($id === null) {
            throw new FinanceRuleException("Configure the {$label} account in payroll settings first.");
        }

        return $this->resolvePostable($id);
    }

    /**
     * Salary bank file for a posted run: one beneficiary row per net-paid
     * employee, plus any employees missing an IBAN.
     *
     * @return array{reference: string, pay_date: string, rows: list<array{employee_code: string, name: string, iban: ?string, amount: float}>, missing_iban: list<string>, total: float}
     */
    public function bankFile(PayrollRun $run): array
    {
        if ($run->status !== 'posted') {
            throw new FinanceRuleException('A bank file can only be generated for a posted run.');
        }

        $employees = PayrollEmployee::query()->whereIn('id', $run->lines->pluck('payroll_employee_id'))->get()->keyBy('id');

        $rows = [];
        $missing = [];
        $total = 0.0;
        foreach ($run->lines as $line) {
            $net = round((float) $line->net_pay, 2);
            if ($net <= 0) {
                continue;
            }
            $emp = $employees->get($line->payroll_employee_id);
            $iban = $emp?->iban;
            $rows[] = ['employee_code' => $emp?->employee_code ?? '', 'name' => $line->employee_name, 'iban' => $iban, 'amount' => $net];
            if ($iban === null || $iban === '') {
                $missing[] = $line->employee_name;
            }
            $total = round($total + $net, 2);
        }

        return ['reference' => $run->reference, 'pay_date' => (string) $run->pay_date->toDateString(), 'rows' => $rows, 'missing_iban' => $missing, 'total' => $total];
    }

    public function approve(PayrollRun $run, int $userId): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new FinanceRuleException('Only a draft payroll run can be approved.');
        }
        $run->update(['status' => 'approved', 'approved_by' => $userId]);

        return $run->fresh('lines');
    }

    /** Post the balanced payroll journal to the GL. */
    public function post(PayrollRun $run, int $userId): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new FinanceRuleException('Only an approved payroll run can be posted.');
        }
        $s = PayrollSetting::current();
        $accounts = [
            'basic' => $s->basic_salary_account_id, 'housing' => $s->housing_account_id, 'transport' => $s->transport_account_id,
            'other' => $s->other_earnings_account_id, 'gosi_exp' => $s->gosi_expense_account_id, 'gosi_pay' => $s->gosi_payable_account_id,
            'eosb_exp' => $s->eosb_expense_account_id, 'eosb_prov' => $s->eosb_provision_account_id, 'net' => $s->net_payable_account_id,
        ];
        foreach ($accounts as $key => $id) {
            if ($id === null) {
                throw new FinanceRuleException('Payroll GL account mapping is incomplete — configure payroll settings first.');
            }
        }

        $lines = $run->lines;
        $sum = fn (string $col): float => round((float) $lines->sum($col), 2);

        $postingLines = [];
        $add = function (int $accountId, float $debit, float $credit, string $desc) use (&$postingLines): void {
            if ($debit > 0 || $credit > 0) {
                $postingLines[] = new PostingLine($this->resolvePostable($accountId), $debit, $credit, 'SAR', 1.0, null, $desc);
            }
        };

        $add((int) $accounts['basic'], $sum('basic'), 0, 'Basic salary');
        $add((int) $accounts['housing'], $sum('housing'), 0, 'Housing allowance');
        $add((int) $accounts['transport'], $sum('transport'), 0, 'Transport allowance');
        $add((int) $accounts['other'], $sum('other_earnings'), 0, 'Other earnings');
        $add((int) $accounts['gosi_exp'], $sum('gosi_employer'), 0, 'GOSI employer contribution');
        $add((int) $accounts['eosb_exp'], $sum('eosb_accrual'), 0, 'EOSB accrual');
        $add((int) $accounts['gosi_pay'], 0, round($sum('gosi_employee') + $sum('gosi_employer'), 2), 'GOSI payable');
        $add((int) $accounts['eosb_prov'], 0, $sum('eosb_accrual'), 'EOSB provision');
        $add((int) $accounts['net'], 0, $sum('net_pay'), 'Net salaries payable');

        // W3 — loan recoveries credit the loan receivable (reducing it).
        $loanRecovery = $this->loans->plannedTotal($run);
        if ($loanRecovery > 0) {
            $add($this->loans->loanReceivableAccountId(), 0, $loanRecovery, 'Loan recovery from payroll');
        }

        return DB::transaction(function () use ($run, $postingLines, $userId): PayrollRun {
            $rows = $this->posting->post($run, Carbon::parse((string) $run->pay_date), $postingLines, $userId);
            $this->loans->applyRecoveries($run);
            $run->update(['status' => 'posted', 'batch_number' => $rows->first()->batch_number]);

            return $run->fresh('lines');
        });
    }
}
