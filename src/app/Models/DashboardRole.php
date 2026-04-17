<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DashboardRole extends Model
{
    protected $table = 'dashboard_roles';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'grants_all',
        'is_system',
    ];

    protected $casts = [
        'grants_all' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(DashboardPermission::class, 'dashboard_permission_role')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dashboard_role_user')
            ->withTimestamps();
    }
}
