<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $table = 'countries';
    protected $guarded = [];

    public function regions()
    {
        return $this->hasMany(Region::class, 'country_id');
    }

    // optional if you still want direct access
    public function charityLocations()
    {
        return $this->hasMany(CharityLocation::class, 'country_id');
    }

    public function charityTransactions()
    {
        return $this->hasMany(CharityTransactions::class, 'country_id');
    }

    public function banks()
    {
        return $this->hasMany(Banks::class, 'country_id');
    }
}
