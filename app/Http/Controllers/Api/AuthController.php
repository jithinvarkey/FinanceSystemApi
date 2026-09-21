<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token-based authentication for the finance SPA.
 *
 * Issues Sanctum personal access tokens; the Angular client stores the
 * plain-text token and sends it as a Bearer header on every request.
 */
final class AuthController extends Controller
{
    /**
     * Verify credentials and mint a personal access token.
     *
     * @throws ValidationException When the credentials do not match.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            // Generic message — never reveal which half of the pair was wrong.
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Contact an administrator.'],
            ]);
        }

        $device = $request->string('device_name')->toString() ?: 'finance-spa';
        $token = $user->createToken($device)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->profile($user),
            ],
        ]);
    }

    /** Revoke the token used for the current request. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    /** Return the authenticated user (used by the SPA to restore session state). */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    /**
     * Public-safe user profile including the effective roles and permissions,
     * so the SPA can gate navigation and hide actions the user cannot perform.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values(),
            'permissions' => $user->permissionNames(),
        ];
    }
}
