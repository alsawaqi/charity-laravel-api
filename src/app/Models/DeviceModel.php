<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceModel extends Model
{
    //

    protected $table = 'device_models';

    protected $guarded = [];

    public function devices()
    {
        return $this->hasMany(Devices::class, 'device_model_id');
    }

    
    public function deviceBrand()
    {
        return $this->belongsTo(DeviceBrand::class, 'device_brand_id');
    }
}
