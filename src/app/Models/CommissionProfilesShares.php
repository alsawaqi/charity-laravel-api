<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionProfilesShares extends Model
{
    protected $table = 'commission_profile_shares';

    protected $guarded = [];


    public function commissionProfile()
    {
        return $this->belongsTo(CommissionProfiles::class, 'commission_profile_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
