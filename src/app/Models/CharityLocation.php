<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CharityLocation extends Model
{
    //

   use HasFactory;

    protected $table = 'charity_locations';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'contact_person_name',
        'contact_person_phone',
        'contact_person_email',
        'address_line1',
        'address_line2',
        'postal_code',
        'notes',
        'is_active',
        'country_id',
        'region_id',
        'city_id',
        'organization_id',
        'main_location_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];



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

     public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    // use snake-case so JSON key is "main_location"
    public function main_location()
    {
        return $this->belongsTo(MainLocation::class);
    }


    public function charityTransactions()
    {
        return $this->hasMany(CharityTransactions::class, 'charity_location_id');
    }
}
