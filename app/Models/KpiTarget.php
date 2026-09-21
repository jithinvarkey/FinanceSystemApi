<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * N3 — a KPI catalog entry + editable target.
 */
final class KpiTarget extends Model
{
    protected $fillable = ['kpi_key', 'name', 'category', 'unit', 'direction', 'target', 'audiences', 'display_order'];

    protected $casts = ['target' => 'decimal:2', 'display_order' => 'integer'];
}
