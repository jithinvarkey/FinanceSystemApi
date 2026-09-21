<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * F18 — Role & permission administration. List roles with their permissions and
 * user counts, edit a role's permission set, and create/remove custom roles.
 * System roles cannot be deleted. Admin-only (finance-config.manage).
 */
final class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $roles = Role::query()->with('permissions:id,name')->withCount('users')->orderBy('label')->get();

        return response()->json(['data' => RoleResource::collection($roles)]);
    }

    /** The full permission catalogue, grouped by module, for the role editor. */
    public function permissions(): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $grouped = Permission::query()->orderBy('name')->get(['id', 'name', 'label', 'module'])
            ->groupBy('module')
            ->map(fn ($items, $module): array => [
                'module' => $module,
                'permissions' => $items->map(fn ($p): array => ['name' => $p->name, 'label' => $p->label])->values(),
            ])
            ->values();

        return response()->json(['data' => $grouped]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role = Role::query()->create([
            'name' => $this->uniqueName($data['label']),
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);
        $this->syncPermissions($role, $data['permissions'] ?? []);

        return response()->json(['data' => new RoleResource($role->load('permissions')->loadCount('users'))], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role->update(['label' => $data['label'], 'description' => $data['description'] ?? null]);
        $this->syncPermissions($role, $data['permissions'] ?? []);

        return response()->json(['data' => new RoleResource($role->load('permissions')->loadCount('users'))]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('finance-config.manage');

        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Reassign the users on this role before deleting it.'], 422);
        }

        $role->delete();

        return response()->json(null, 204);
    }

    /** @param list<string> $permissionNames */
    private function syncPermissions(Role $role, array $permissionNames): void
    {
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $role->permissions()->sync($ids);
    }

    private function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'role';
        $name = $base;
        $i = 2;
        while (Role::query()->where('name', $name)->exists()) {
            $name = "{$base}-{$i}";
            $i++;
        }

        return $name;
    }
}
