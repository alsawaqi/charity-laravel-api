<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DashboardPermission extends Model
{
    protected $table = 'dashboard_permissions';

    protected $fillable = [
        'key',
        'label',
        'group',
        'path',
        'description',
        'sort_order',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(DashboardRole::class, 'dashboard_permission_role')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dashboard_permission_user')
            ->withTimestamps();
    }
}
