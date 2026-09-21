<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * W1 — a monthly payroll run.
 */
final class PayrollRun extends Model
{
    protected $fillable = [
        'reference', 'run_type', 'payroll_employee_id', 'period_year', 'period_month', 'pay_date', 'gross_total', 'gosi_employee_total',
        'gosi_employer_total', 'eosb_total', 'net_total', 'status', 'batch_number', 'created_by', 'approved_by',
    ];

    protected $casts = [
        'period_year' => 'integer', 'period_month' => 'integer', 'pay_date' => 'date',
        'gross_total' => 'decimal:2', 'gosi_employee_total' => 'decimal:2', 'gosi_employer_total' => 'decimal:2',
        'eosb_total' => 'decimal:2', 'net_total' => 'decimal:2',
    ];

    /** @return HasMany<PayrollRunLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class);
    }
}
