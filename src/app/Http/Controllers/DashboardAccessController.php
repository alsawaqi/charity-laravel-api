<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Models\DashboardRole;
use Illuminate\Support\Str;
use App\Models\Organization;
use Illuminate\Validation\Rule;
use App\Models\DashboardPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DashboardAccessController extends Controller
{
    public function permissions()
    {
        return response()->json([
            'data' => DashboardPermission::query()
                ->orderBy('group')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (DashboardPermission $permission) => [
                    'id' => $permission->id,
                    'key' => $permission->key,
                    'label' => $permission->label,
                    'group' => $permission->group,
                    'path' => $permission->path,
                    'description' => $permission->description,
                    'sort_order' => $permission->sort_order,
                ])
                ->values(),
        ]);
    }

    public function roles()
    {
        $roles = DashboardRole::query()
            ->with(['permissions:id,key,label,group,path', 'users:id'])
            ->orderByDesc('grants_all')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (DashboardRole $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'grants_all' => (bool) $role->grants_all,
                'is_system' => (bool) $role->is_system,
                'permissions_count' => $role->permissions->count(),
                'users_count' => $role->users->count(),
                'permission_ids' => $role->permissions->pluck('id')->values(),
                'permissions' => $role->permissions->map(fn (DashboardPermission $permission) => [
                    'id' => $permission->id,
                    'key' => $permission->key,
                    'label' => $permission->label,
                    'group' => $permission->group,
                    'path' => $permission->path,
                ])->values(),
            ])->values(),
        ]);
    }

    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:dashboard_roles,name'],
            'description' => ['nullable', 'string'],
            'grants_all' => ['nullable', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:dashboard_permissions,id'],
        ]);

        $grantsAll = (bool) ($data['grants_all'] ?? false);
        $permissionIds = array_values(array_unique($data['permission_ids'] ?? []));

        if (!$grantsAll && empty($permissionIds)) {
            return response()->json([
                'message' => 'Select at least one permission or enable full access for this role.',
            ], 422);
        }

        $role = DB::transaction(function () use ($data, $grantsAll, $permissionIds) {
            $role = DashboardRole::create([
                'name' => $data['name'],
                'slug' => $this->makeUniqueRoleSlug($data['name']),
                'description' => $data['description'] ?? null,
                'grants_all' => $grantsAll,
                'is_system' => false,
            ]);

            $role->permissions()->sync($grantsAll ? [] : $permissionIds);

            return $role->load('permissions:id,key,label,group,path');
        });

        return response()->json([
            'message' => 'Role created successfully.',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'grants_all' => (bool) $role->grants_all,
                'is_system' => (bool) $role->is_system,
                'permission_ids' => $role->permissions->pluck('id')->values(),
            ],
        ], 201);
    }

    public function updateRole(Request $request, DashboardRole $dashboardRole)
    {
        if ($dashboardRole->is_system) {
            return response()->json([
                'message' => 'System roles cannot be edited.',
            ], 422);
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('dashboard_roles', 'name')->ignore($dashboardRole->id),
            ],
            'description' => ['nullable', 'string'],
            'grants_all' => ['nullable', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:dashboard_permissions,id'],
        ]);

        $grantsAll = (bool) ($data['grants_all'] ?? false);
        $permissionIds = array_values(array_unique($data['permission_ids'] ?? []));

        if (!$grantsAll && empty($permissionIds)) {
            return response()->json([
                'message' => 'Select at least one permission or enable full access for this role.',
            ], 422);
        }

        DB::transaction(function () use ($dashboardRole, $data, $grantsAll, $permissionIds) {
            $dashboardRole->update([
                'name' => $data['name'],
                'slug' => $this->makeUniqueRoleSlug($data['name'], $dashboardRole->id),
                'description' => $data['description'] ?? null,
                'grants_all' => $grantsAll,
            ]);

            $dashboardRole->permissions()->sync($grantsAll ? [] : $permissionIds);
        });

        return response()->json([
            'message' => 'Role updated successfully.',
        ]);
    }

    public function destroyRole(DashboardRole $dashboardRole)
    {
        if ($dashboardRole->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted.',
            ], 422);
        }

        if ($dashboardRole->users()->exists()) {
            return response()->json([
                'message' => 'Remove this role from users before deleting it.',
            ], 422);
        }

        $dashboardRole->delete();

        return response()->json([
            'message' => 'Role deleted successfully.',
        ]);
    }

    public function users(Request $request)
    {
        $perPage = min(100, max(5, (int) $request->input('per_page', 10)));
        $search = trim((string) $request->input('search', ''));

        $paginator = User::query()
            ->with([
                'organization:id,name',
                'dashboardRoles:id,name,slug,grants_all',
                'dashboardDirectPermissions:id,key,label,group,path',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (User $user) => $this->transformUser($user))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $this->validateUserPayload($request);
        $roleIds = array_values(array_unique($data['dashboard_role_ids'] ?? []));
        $permissionIds = array_values(array_unique($data['dashboard_permission_ids'] ?? []));

        if (empty($roleIds) && empty($permissionIds)) {
            return response()->json([
                'message' => 'Assign at least one role or direct permission to approve dashboard access.',
            ], 422);
        }

        $user = DB::transaction(function () use ($data, $roleIds, $permissionIds) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'organization_id' => $data['organization_id'] ?? null,
            ]);

            $user->dashboardRoles()->sync($roleIds);
            $user->dashboardDirectPermissions()->sync($permissionIds);

            return $user->fresh([
                'organization:id,name',
                'dashboardRoles:id,name,slug,grants_all',
                'dashboardDirectPermissions:id,key,label,group,path',
            ]);
        });

        return response()->json([
            'message' => 'Dashboard user created successfully.',
            'data' => $this->transformUser($user),
        ], 201);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $this->validateUserPayload($request, $user);
        $roleIds = array_values(array_unique($data['dashboard_role_ids'] ?? []));
        $permissionIds = array_values(array_unique($data['dashboard_permission_ids'] ?? []));

        if ($this->wouldRemoveLastOwner($user, $roleIds)) {
            return response()->json([
                'message' => 'At least one owner must remain assigned to the dashboard.',
            ], 422);
        }

        if ($request->user()->is($user) && empty($roleIds) && empty($permissionIds)) {
            return response()->json([
                'message' => 'You cannot remove your own dashboard access.',
            ], 422);
        }

        DB::transaction(function () use ($data, $user, $roleIds, $permissionIds) {
            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'organization_id' => $data['organization_id'] ?? null,
            ];

            if (!empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
            }

            $user->update($payload);
            $user->dashboardRoles()->sync($roleIds);
            $user->dashboardDirectPermissions()->sync($permissionIds);
        });

        return response()->json([
            'message' => 'Dashboard user updated successfully.',
            'data' => $this->transformUser($user->fresh([
                'organization:id,name',
                'dashboardRoles:id,name,slug,grants_all',
                'dashboardDirectPermissions:id,key,label,group,path',
            ])),
        ]);
    }

    public function destroyUser(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return response()->json([
                'message' => 'You cannot delete your own user.',
            ], 422);
        }

        if ($user->hasDashboardFullAccess() && $this->ownerCountExcluding($user->id) === 0) {
            return response()->json([
                'message' => 'At least one owner must remain assigned to the dashboard.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'Dashboard user deleted successfully.',
        ]);
    }

    public function organizations()
    {
        return response()->json([
            'data' => Organization::query()
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function validateUserPayload(Request $request, ?User $user = null): array
    {
        $passwordRules = $user
            ? ['nullable', 'string', 'min:8', 'confirmed']
            : ['required', 'string', 'min:8', 'confirmed'];

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => $passwordRules,
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'dashboard_role_ids' => ['nullable', 'array'],
            'dashboard_role_ids.*' => ['integer', 'exists:dashboard_roles,id'],
            'dashboard_permission_ids' => ['nullable', 'array'],
            'dashboard_permission_ids.*' => ['integer', 'exists:dashboard_permissions,id'],
        ]);
    }

    private function transformUser(User $user): array
    {
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
            'dashboard_roles' => $user->dashboardRoles->map(fn (DashboardRole $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'grants_all' => (bool) $role->grants_all,
            ])->values(),
            'dashboard_role_ids' => $user->dashboardRoles->pluck('id')->values(),
            'dashboard_direct_permissions' => $user->dashboardDirectPermissions
                ->map(fn (DashboardPermission $permission) => [
                    'id' => $permission->id,
                    'key' => $permission->key,
                    'label' => $permission->label,
                    'group' => $permission->group,
                    'path' => $permission->path,
                ])->values(),
            'dashboard_permission_ids' => $user->dashboardDirectPermissions->pluck('id')->values(),
            'resolved_dashboard_permissions' => $permissions
                ->map(fn (DashboardPermission $permission) => [
                    'id' => $permission->id,
                    'key' => $permission->key,
                    'label' => $permission->label,
                    'group' => $permission->group,
                    'path' => $permission->path,
                ])->values(),
        ];
    }

    private function ownerCountExcluding(?int $excludedUserId = null): int
    {
        return User::query()
            ->when($excludedUserId, fn ($query) => $query->whereKeyNot($excludedUserId))
            ->whereHas('dashboardRoles', fn ($query) => $query->where('grants_all', true))
            ->count();
    }

    private function wouldRemoveLastOwner(User $user, array $roleIds): bool
    {
        $roles = DashboardRole::query()
            ->whereIn('id', $roleIds)
            ->get(['id', 'grants_all']);

        if ($roles->contains(fn (DashboardRole $role) => $role->grants_all)) {
            return false;
        }

        if (!$user->hasDashboardFullAccess()) {
            return false;
        }

        return $this->ownerCountExcluding($user->id) === 0;
    }

    private function makeUniqueRoleSlug(string $name, ?int $ignoreRoleId = null): string
    {
        $baseSlug = Str::slug($name) ?: 'role';
        $slug = $baseSlug;
        $suffix = 2;

        while (
            DashboardRole::query()
                ->when($ignoreRoleId, fn ($query) => $query->whereKeyNot($ignoreRoleId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
