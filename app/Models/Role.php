<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * P0.1 — A named bundle of permissions assigned to users
 * (Accountant, Finance Controller, Finance Manager, IT Supervisor, Auditor).
 */
final class Role extends Model
{
    protected $fillable = ['name', 'label', 'description', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    /** @return BelongsToMany<Permission> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** @return BelongsToMany<User> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
