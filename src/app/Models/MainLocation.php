<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MainLocation extends Model
{
    use HasFactory;

    protected $table = 'main_locations';

    protected $fillable = [
        'country_id',
        'region_id',
        'city_id',
        'organization_id',
        'district_id',
        'name',
        'company_id'
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }


    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }


    public function company()
    {
        return $this->belongsTo(Company::class);
    }


    public function charityLocations()
    {
        return $this->hasMany(CharityLocation::class, 'main_location_id');
    }


    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
