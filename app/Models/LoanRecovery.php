<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W3 — a planned loan recovery for a payroll run (applied on posting).
 */
final class LoanRecovery extends Model
{
    protected $fillable = ['payroll_run_id', 'employee_loan_id', 'amount', 'applied'];

    protected $casts = ['amount' => 'decimal:2', 'applied' => 'boolean'];

    /** @return BelongsTo<EmployeeLoan, LoanRecovery> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }
}
