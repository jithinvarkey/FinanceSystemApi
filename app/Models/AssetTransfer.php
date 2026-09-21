<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E10 — an audit row for a fixed-asset transfer (cost centre / location / custodian).
 */
final class AssetTransfer extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'transfer_date', 'from_cost_center_id', 'to_cost_center_id',
        'from_location', 'to_location', 'from_custodian', 'to_custodian', 'reason', 'created_by',
    ];

    protected $casts = ['transfer_date' => 'date'];
}
