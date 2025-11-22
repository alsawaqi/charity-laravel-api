<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharityLocation extends Model
{
    //

    protected $table = 'charity_locations';

    protected $guarded = [];


    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }
    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }
    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id');
    }
    public function charityTransactions()
    {
        return $this->hasMany(CharityTransactions::class, 'charity_location_id');
    }
}
