<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W1 — finance-side employee master (salary structure + GOSI treatment).
 */
final class PayrollEmployee extends Model
{
    protected $fillable = [
        'employee_code', 'name', 'is_saudi', 'basic_salary', 'housing_allowance', 'transport_allowance',
        'other_allowance', 'cost_center_id', 'salary_grade_id', 'join_date', 'end_date', 'iban', 'status', 'created_by',
    ];

    protected $casts = [
        'is_saudi' => 'boolean', 'basic_salary' => 'decimal:2', 'housing_allowance' => 'decimal:2',
        'transport_allowance' => 'decimal:2', 'other_allowance' => 'decimal:2', 'join_date' => 'date', 'end_date' => 'date',
    ];

    /** Monthly gross = basic + all allowances. */
    public function grossSalary(): float
    {
        return round((float) $this->basic_salary + (float) $this->housing_allowance + (float) $this->transport_allowance + (float) $this->other_allowance, 2);
    }

    /** GOSI applies to basic + housing (Saudi base). */
    public function gosiBase(): float
    {
        return round((float) $this->basic_salary + (float) $this->housing_allowance, 2);
    }
}
