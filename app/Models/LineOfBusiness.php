<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.17 — Line of business (Motor, Medical, General, Life, …). `is_life` drives
 * the VAT-exempt treatment of premium and commission.
 */
final class LineOfBusiness extends Model
{
    use SoftDeletes;

    protected $table = 'lines_of_business';

    protected $fillable = ['code', 'name', 'parent_id', 'is_life', 'status'];

    protected $casts = ['is_life' => 'boolean'];

    /** @return BelongsTo<LineOfBusiness, LineOfBusiness> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Product> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'lob_id');
    }
}
