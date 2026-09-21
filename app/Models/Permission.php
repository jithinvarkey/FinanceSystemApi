<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * P0.1 — A single grantable ability, e.g. "general-ledger.approve".
 */
final class Permission extends Model
{
    protected $fillable = ['name', 'label', 'module'];

    /** @return BelongsToMany<Role> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
