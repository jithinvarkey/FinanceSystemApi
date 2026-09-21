<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W5 — a salary grade (band).
 */
final class SalaryGrade extends Model
{
    protected $fillable = ['code', 'name', 'min_salary', 'mid_salary', 'max_salary', 'status'];

    protected $casts = ['min_salary' => 'decimal:2', 'mid_salary' => 'decimal:2', 'max_salary' => 'decimal:2'];

    public function contains(float $salary): bool
    {
        return $salary >= (float) $this->min_salary - 0.01 && $salary <= (float) $this->max_salary + 0.01;
    }
}
