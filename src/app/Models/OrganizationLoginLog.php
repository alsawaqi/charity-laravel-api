<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationLoginLog extends Model
{
    protected $table = 'organization_login_logs';

    protected $fillable = [
        'user_id',
        'organization_id',
        'session_id',
        'ip_address',
        'user_agent',
        'login_at',
        'logout_at',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'logout_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
