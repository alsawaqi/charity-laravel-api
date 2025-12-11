<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class District extends Model
{
    use HasFactory;

    protected $fillable = [
        'region_id',
        'name',
    ];

    // district → region
    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    // Optional: if you already added district_id to these tables
    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function mainLocations()
    {
        return $this->hasMany(MainLocation::class);
    }

    public function charityLocations()
    {
        return $this->hasMany(CharityLocation::class);
    }
}