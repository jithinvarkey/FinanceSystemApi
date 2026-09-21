<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W3 — an employee loan or salary advance recovered through payroll.
 */
final class EmployeeLoan extends Model
{
    protected $fillable = [
        'reference', 'payroll_employee_id', 'loan_type', 'principal', 'monthly_installment',
        'outstanding_balance', 'disbursed_date', 'status', 'batch_number', 'notes', 'created_by',
    ];

    protected $casts = [
        'principal' => 'decimal:2', 'monthly_installment' => 'decimal:2', 'outstanding_balance' => 'decimal:2', 'disbursed_date' => 'date',
    ];

    /** @return BelongsTo<PayrollEmployee, EmployeeLoan> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }

    /** This month's recovery — instalment capped at the outstanding balance. */
    public function plannedInstallment(): float
    {
        return round(min((float) $this->monthly_installment, (float) $this->outstanding_balance), 2);
    }
}
