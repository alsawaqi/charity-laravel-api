<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceBrand extends Model
{
    //

    protected $table = 'device_brands';

    protected $guarded = [];

    public function devices()
    {
        return $this->hasMany(Devices::class, 'device_brand_id');


        
    }

     public function deviceModels()
    {
        return $this->hasMany(DeviceModel::class, 'device_brand_id');
    }
}
