<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * F17 — User administration: list, create, edit, (de)activate users and assign
 * roles. Admin-only (gated on finance-config.manage, which only the
 * IT-Supervisor role holds). Passwords are never returned.
 */
final class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('finance-config.manage');

        return response()->json([
            'data' => UserResource::collection(User::query()->with('roles')->orderBy('name')->get()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->roles()->sync($data['role_ids'] ?? []);

        return response()->json(['data' => new UserResource($user->load('roles'))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        // An admin cannot lock themselves out.
        if ($user->id === (int) $request->user()->id && isset($data['is_active']) && ! $data['is_active']) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'] ?? $user->is_active,
        ]);
        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids']);
        }

        return response()->json(['data' => new UserResource($user->load('roles'))]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);
        $user->update(['password' => Hash::make($data['password'])]);
        $user->tokens()->delete(); // force re-login everywhere

        return response()->json(['data' => new UserResource($user->load('roles'))]);
    }

    /** Distinct roles for the assignment picker. */
    public function roles(): JsonResponse
    {
        $this->authorize('finance-config.manage');

        return response()->json([
            'data' => Role::query()->orderBy('label')->get(['id', 'name', 'label']),
        ]);
    }
}
