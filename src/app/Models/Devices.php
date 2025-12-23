<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devices extends Model
{
    //

    protected $table = 'devices';

    protected $fillable = [
        'companies_id',
        'main_location_id',
        'device_brand_id',
        'device_model_id',
        'bank_id',
        'model_number',
        'country_id',
        'region_id',
        'city_id',
        'district_id',
        'charity_location_id',
        'commission_profile_id',
        'kiosk_id',
        'login_generated_token',
        'status',
        'installed_at',
    ];

    protected $casts = [
        'installed_at' => 'date',
    ];

    public function DeviceBrand()
    {
        return $this->belongsTo(DeviceBrand::class, 'device_brand_id');
    }

    public function DeviceModel()
    {
        return $this->belongsTo(DeviceModel::class, 'device_model_id');
    }


    public function bank()
    {
        return $this->belongsTo(Banks::class, 'bank_id');
    }


    public function company()
    {
        return $this->belongsTo(Company::class, 'companies_id');
    }

    public function mainLocation()
    {
        return $this->belongsTo(MainLocation::class, 'main_location_id');
    }


    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }


    public function commissionProfile()
    {
        return $this->belongsTo(CommissionProfiles::class, 'commission_profile_id');
    }

    public function charityLocation()
    {
        return $this->belongsTo(CharityLocation::class, 'charity_location_id');
    }
}
