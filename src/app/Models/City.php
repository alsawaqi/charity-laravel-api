<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $table = 'cities';
    protected $guarded = [];

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function charityLocations()
    {
        return $this->hasMany(CharityLocation::class, 'city_id');
    }
}
