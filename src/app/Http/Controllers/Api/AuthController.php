<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function transformUser(User $user): array
    {
        $user->loadMissing(
            'organization:id,name',
            'dashboardRoles.permissions',
            'dashboardDirectPermissions'
        );

        $permissions = $user->resolvedDashboardPermissions();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'organization_id' => $user->organization_id,
            'organization' => $user->organization
                ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                ]
                : null,
            'dashboard_access' => [
                'has_access' => $user->canAccessDashboard(),
                'full_access' => $user->hasDashboardFullAccess(),
            ],
            'dashboard_roles' => $user->dashboardRoles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'grants_all' => (bool) $role->grants_all,
            ])->values(),
            'dashboard_permissions' => $permissions->map(fn ($permission) => [
                'id' => $permission->id,
                'key' => $permission->key,
                'label' => $permission->label,
                'group' => $permission->group,
                'path' => $permission->path,
            ])->values(),
        ];
    }

    public function register(Request $request)
    {
        return response()->json([
            'message' => 'Self-service registration is disabled for the admin dashboard.',
        ], 403);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->canAccessDashboard()) {
            return response()->json([
                'message' => 'You are not allowed to access the admin dashboard.',
            ], 403);
        }

        // Optionally delete old tokens for this device/session
        // $user->tokens()->delete();

        $token = $user->createToken('charity-dashboard')->plainTextToken;

        return response()->json([
            'user'  => $this->transformUser($user),
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->transformUser($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke current token only
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out',
        ]);
    }
}
