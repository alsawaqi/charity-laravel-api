<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable,HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
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
        ];
    }


    

     public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }


    public function cashCollections(): HasMany
    {
        return $this->hasMany(CashCollection::class, 'collected_by_user_id');
    }

    public function dashboardRoles(): BelongsToMany
    {
        return $this->belongsToMany(DashboardRole::class, 'dashboard_role_user')
            ->withTimestamps();
    }

    public function dashboardDirectPermissions(): BelongsToMany
    {
        return $this->belongsToMany(DashboardPermission::class, 'dashboard_permission_user')
            ->withTimestamps();
    }

    public function hasDashboardFullAccess(): bool
    {
        $this->loadMissing('dashboardRoles');

        return $this->dashboardRoles->contains(
            fn (DashboardRole $role) => (bool) $role->grants_all
        );
    }

    public function resolvedDashboardPermissions(): Collection
    {
        $this->loadMissing('dashboardRoles.permissions', 'dashboardDirectPermissions');

        if ($this->hasDashboardFullAccess()) {
            return DashboardPermission::query()
                ->orderBy('group')
                ->orderBy('sort_order')
                ->get();
        }

        return $this->dashboardRoles
            ->flatMap(fn (DashboardRole $role) => $role->permissions)
            ->merge($this->dashboardDirectPermissions)
            ->unique('id')
            ->sortBy(function (DashboardPermission $permission) {
                return sprintf(
                    '%s-%05d',
                    $permission->group ?? 'zzzz',
                    $permission->sort_order ?? 99999
                );
            })
            ->values();
    }

    public function canAccessDashboard(): bool
    {
        return $this->hasDashboardFullAccess() || $this->resolvedDashboardPermissions()->isNotEmpty();
    }

    public function hasDashboardPermission(string $permissionKey): bool
    {
        return $this->hasDashboardFullAccess()
            || $this->resolvedDashboardPermissions()->contains('key', $permissionKey);
    }
}
