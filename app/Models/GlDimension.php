<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * F27 — a GL analysis dimension value (e.g. a project or segment).
 */
final class GlDimension extends Model
{
    protected $fillable = ['type', 'code', 'name', 'status'];
}
