<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W2 — a benefit cost event (provision accrual, utilisation or direct expense).
 */
final class BenefitCost extends Model
{
    protected $fillable = ['employee_benefit_id', 'cost_date', 'amount', 'kind', 'description', 'batch_number', 'created_by'];

    protected $casts = ['cost_date' => 'date', 'amount' => 'decimal:2'];
}
