<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Payroll W4 — workforce cost allocation & analytics over posted payroll runs.
 * Employer cost per payslip = gross + employer GOSI + EOSB accrual.
 */
final class WorkforceReportService
{
    /**
     * @return array{
     *   year: int,
     *   summary: array{gross: float, gosi_employer: float, eosb: float, net: float, total_cost: float, headcount: int, runs: int},
     *   by_cost_center: list<array{cost_center: string, headcount: int, gross: float, employer_cost: float, total_cost: float}>,
     *   monthly_trend: list<array{month: int, gross: float, net: float, total_cost: float}>
     * }
     */
    public function analytics(int $year): array
    {
        // One row per payslip on posted runs in the year, with the employee's cost centre.
        $rows = DB::table('payroll_run_lines as l')
            ->join('payroll_runs as r', 'r.id', '=', 'l.payroll_run_id')
            ->join('payroll_employees as e', 'e.id', '=', 'l.payroll_employee_id')
            ->leftJoin('cost_centers as c', 'c.id', '=', 'e.cost_center_id')
            ->where('r.status', 'posted')->where('r.period_year', $year)
            ->selectRaw('r.period_month, l.payroll_employee_id, COALESCE(c.name, ?) cc, l.gross, l.gosi_employer, l.eosb_accrual, l.net_pay', ['(unallocated)'])
            ->get();

        $summary = ['gross' => 0.0, 'gosi_employer' => 0.0, 'eosb' => 0.0, 'net' => 0.0, 'total_cost' => 0.0];
        $byCc = [];
        $byMonth = [];
        $employees = [];
        $runs = [];

        foreach ($rows as $r) {
            $cost = round((float) $r->gross + (float) $r->gosi_employer + (float) $r->eosb_accrual, 2);
            $summary['gross'] = round($summary['gross'] + (float) $r->gross, 2);
            $summary['gosi_employer'] = round($summary['gosi_employer'] + (float) $r->gosi_employer, 2);
            $summary['eosb'] = round($summary['eosb'] + (float) $r->eosb_accrual, 2);
            $summary['net'] = round($summary['net'] + (float) $r->net_pay, 2);
            $summary['total_cost'] = round($summary['total_cost'] + $cost, 2);
            $employees[$r->payroll_employee_id] = true;

            $cc = (string) $r->cc;
            $byCc[$cc] ??= ['headcount' => [], 'gross' => 0.0, 'employer_cost' => 0.0, 'total_cost' => 0.0];
            $byCc[$cc]['headcount'][$r->payroll_employee_id] = true;
            $byCc[$cc]['gross'] = round($byCc[$cc]['gross'] + (float) $r->gross, 2);
            $byCc[$cc]['employer_cost'] = round($byCc[$cc]['employer_cost'] + (float) $r->gosi_employer + (float) $r->eosb_accrual, 2);
            $byCc[$cc]['total_cost'] = round($byCc[$cc]['total_cost'] + $cost, 2);

            $m = (int) $r->period_month;
            $byMonth[$m] ??= ['gross' => 0.0, 'net' => 0.0, 'total_cost' => 0.0];
            $byMonth[$m]['gross'] = round($byMonth[$m]['gross'] + (float) $r->gross, 2);
            $byMonth[$m]['net'] = round($byMonth[$m]['net'] + (float) $r->net_pay, 2);
            $byMonth[$m]['total_cost'] = round($byMonth[$m]['total_cost'] + $cost, 2);
        }

        $byCenter = [];
        foreach ($byCc as $cc => $v) {
            $byCenter[] = [
                'cost_center' => $cc, 'headcount' => count($v['headcount']),
                'gross' => $v['gross'], 'employer_cost' => $v['employer_cost'], 'total_cost' => $v['total_cost'],
            ];
        }
        usort($byCenter, fn (array $a, array $b): int => $b['total_cost'] <=> $a['total_cost']);

        $trend = [];
        ksort($byMonth);
        foreach ($byMonth as $m => $v) {
            $trend[] = ['month' => $m, 'gross' => $v['gross'], 'net' => $v['net'], 'total_cost' => $v['total_cost']];
        }

        return [
            'year' => $year,
            'summary' => [...$summary, 'headcount' => count($employees), 'runs' => DB::table('payroll_runs')->where('status', 'posted')->where('period_year', $year)->count()],
            'by_cost_center' => $byCenter,
            'monthly_trend' => $trend,
        ];
    }
}
