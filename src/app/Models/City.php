<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $table = 'cities';
    protected $fillable = [
        'region_id',
        'name',
        'district_id',
        'postal_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id');
    }


    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function mainLocations()
    {
        return $this->hasMany(MainLocation::class, 'city_id');
    }

    public function charityLocations()
    {
        return $this->hasMany(CharityLocation::class, 'city_id');
    }
}
