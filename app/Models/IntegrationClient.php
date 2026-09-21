<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inbound integration (P-INT) — an upstream system authorised to PUSH events.
 * Authenticates with an API key; only the sha256 hash is stored.
 */
final class IntegrationClient extends Model
{
    protected $fillable = [
        'name', 'source_system', 'key_hash', 'acts_as_user_id', 'is_active', 'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /** The maker that ingested drafts are attributed to (created_by). */
    public function actsAsUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acts_as_user_id');
    }

    /** @return HasMany<IntegrationEvent> */
    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    /** Resolve an active client from a raw API key, or null. */
    public static function resolve(string $rawKey): ?self
    {
        if ($rawKey === '') {
            return null;
        }

        return self::query()
            ->where('key_hash', hash('sha256', $rawKey))
            ->where('is_active', true)
            ->first();
    }

    /** Hash a raw key for storage. */
    public static function hashKey(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }
}
