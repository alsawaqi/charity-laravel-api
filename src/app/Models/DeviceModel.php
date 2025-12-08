<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeviceModel extends Model
{
    //
    use HasFactory;

    protected $table = 'device_models';

    protected $fillable = [
        'device_brand_id',
        'name',
    ];


    public function devices()
    {
        return $this->hasMany(Devices::class, 'device_model_id');
    }

    
    public function deviceBrand()
    {
        return $this->belongsTo(DeviceBrand::class, 'device_brand_id');
    }
}
