<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecurringFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FIN-0039 — Recurring journal template.
 *
 * @property RecurringFrequency $frequency
 */
final class RecurringJournal extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_code', 'name', 'description', 'frequency', 'day_of_month',
        'start_date', 'end_date', 'next_run_date', 'status', 'currency_code',
        'created_by',
    ];

    protected $casts = [
        'frequency' => RecurringFrequency::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_date' => 'date',
        'day_of_month' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringJournalLine::class)->orderBy('line_no');
    }

    public function generatedJournals(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
