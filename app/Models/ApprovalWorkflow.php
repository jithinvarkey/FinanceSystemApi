<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P0.4 — Approval definition for one document type (its ordered steps).
 */
final class ApprovalWorkflow extends Model
{
    protected $fillable = ['document_type', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** @return HasMany<ApprovalStep> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('sequence');
    }
}
