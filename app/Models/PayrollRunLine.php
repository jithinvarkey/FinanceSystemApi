<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W1 — one employee's payslip within a payroll run.
 */
final class PayrollRunLine extends Model
{
    protected $fillable = [
        'payroll_run_id', 'payroll_employee_id', 'employee_name', 'basic', 'housing', 'transport',
        'other_earnings', 'gross', 'gosi_employee', 'gosi_employer', 'eosb_accrual', 'other_deductions', 'net_pay',
    ];

    protected $casts = [
        'basic' => 'decimal:2', 'housing' => 'decimal:2', 'transport' => 'decimal:2', 'other_earnings' => 'decimal:2',
        'gross' => 'decimal:2', 'gosi_employee' => 'decimal:2', 'gosi_employer' => 'decimal:2',
        'eosb_accrual' => 'decimal:2', 'other_deductions' => 'decimal:2', 'net_pay' => 'decimal:2',
    ];
}
