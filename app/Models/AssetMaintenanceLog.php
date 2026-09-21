<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E10 — a maintenance event logged against a fixed asset.
 */
final class AssetMaintenanceLog extends Model
{
    protected $fillable = ['fixed_asset_id', 'maintenance_date', 'type', 'description', 'cost', 'vendor', 'created_by'];

    protected $casts = ['maintenance_date' => 'date', 'cost' => 'decimal:2'];
}
