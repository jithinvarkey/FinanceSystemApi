<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W2 — an employee benefit entitlement (air ticket / medical / visa / iqama).
 */
final class EmployeeBenefit extends Model
{
    protected $fillable = [
        'payroll_employee_id', 'benefit_type', 'description', 'annual_amount',
        'accrued_amount', 'utilized_amount', 'renewal_date', 'status', 'created_by',
    ];

    protected $casts = [
        'annual_amount' => 'decimal:2', 'accrued_amount' => 'decimal:2', 'utilized_amount' => 'decimal:2', 'renewal_date' => 'date',
    ];

    /** @return BelongsTo<PayrollEmployee, EmployeeBenefit> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }

    /** Unutilised provision still sitting as a liability. */
    public function outstandingProvision(): float
    {
        return round((float) $this->accrued_amount - (float) $this->utilized_amount, 2);
    }
}
