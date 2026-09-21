<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * N5 — a contract / incentive agreement.
 */
final class Contract extends Model
{
    protected $fillable = [
        'contract_number', 'title', 'party_type', 'party_id', 'party_name',
        'category', 'start_date', 'end_date', 'value', 'status', 'notes', 'created_by',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'value' => 'decimal:2'];
}
