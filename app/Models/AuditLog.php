<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\FinanceRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * FIN-0058 — Immutable audit trail row written by AuditObserver.
 */
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** The audit trail is append-only: rows can never be changed or removed. */
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new FinanceRuleException('Audit log rows are immutable and cannot be modified.');
        });

        static::deleting(static function (): void {
            throw new FinanceRuleException('Audit log rows are immutable and cannot be deleted.');
        });
    }

    protected $fillable = [
        'auditable_type', 'auditable_id', 'event',
        'old_values', 'new_values', 'user_id', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
