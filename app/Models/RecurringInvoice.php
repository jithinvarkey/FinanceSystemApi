<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecurringFrequency;
use Illuminate\Database\Eloquent\Model;

/**
 * A recurring invoice / bill template.
 *
 * @property RecurringFrequency $frequency
 */
final class RecurringInvoice extends Model
{
    protected $fillable = [
        'type', 'party_id', 'name', 'frequency', 'day_of_month', 'start_date', 'next_run_date', 'end_date',
        'is_active', 'reference', 'description', 'due_days', 'lines', 'created_by', 'last_generated_at', 'generated_count',
    ];

    protected $casts = [
        'frequency' => RecurringFrequency::class,
        'day_of_month' => 'integer',
        'due_days' => 'integer',
        'start_date' => 'date',
        'next_run_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'lines' => 'array',
        'last_generated_at' => 'datetime',
        'generated_count' => 'integer',
    ];
}
