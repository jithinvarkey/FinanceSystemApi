<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * N4 — a saved self-service report definition.
 */
final class ReportDefinition extends Model
{
    protected $fillable = ['name', 'description', 'measure', 'group_by', 'filters', 'is_shared', 'created_by'];

    protected $casts = ['filters' => 'array', 'is_shared' => 'boolean'];
}
