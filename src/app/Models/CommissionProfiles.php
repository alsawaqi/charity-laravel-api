<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CommissionProfiles extends Model
{
       use HasFactory;

    protected $table = 'commission_profiles';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function shares()
    {
        return $this->hasMany(CommissionProfilesShares::class, 'commission_profile_id');
    }
}
