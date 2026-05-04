<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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
        'terminal_id',
        'device_code',
        'bank_username',
        'bank_password',
        'login_generated_token',
        'status',
        'installed_at',
    ];

    protected $hidden = [
        'bank_username',
        'bank_password',
    ];

    protected $casts = [
        'installed_at' => 'date',
    ];

    protected function bankPassword(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if (blank($value)) {
                    return $value;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    return $value;
                }
            },
            set: fn (?string $value): ?string => blank($value)
                ? $value
                : Crypt::encryptString($value),
        );
    }

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
