<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeviceBrand extends Model
{
    use HasFactory;

    protected $table = 'device_brands';

    protected $fillable = [
        'name',
        'slug',
        'notes',
    ];

    public function devices()
    {
        return $this->hasMany(Devices::class, 'device_brand_id');


        
    }

     public function deviceModels()
    {
        return $this->hasMany(DeviceModel::class, 'device_brand_id');
    }
}
