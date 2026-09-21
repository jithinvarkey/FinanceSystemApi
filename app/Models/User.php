<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // ----- RBAC (P0.1) -----

    /** @return BelongsToMany<Role> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** Does the user hold this role (by machine name)? */
    public function hasRole(string $name): bool
    {
        return $this->roles->contains('name', $name);
    }

    /**
     * The user's effective permission names — the union across all roles.
     * Eager-loads roles.permissions to avoid N+1 on repeated checks.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->loadMissing('roles.permissions')
            ->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    /** Authorization predicate used by Gate::before — does any role grant $ability? */
    public function hasPermission(string $ability): bool
    {
        return in_array($ability, $this->permissionNames(), true);
    }
}
