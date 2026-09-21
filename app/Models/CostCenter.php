<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FIN-0005 — Cost center for departmental cost allocation.
 */
final class CostCenter extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'parent_id', 'manager_id', 'status'];

    protected $casts = ['status' => RecordStatus::class];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
