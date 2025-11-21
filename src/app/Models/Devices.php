<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devices extends Model
{
    //

    protected $table = 'devices';

    protected $guarded = [];

    public function DeviceModel()
    {
        return $this->belongsTo(DeviceModel::class, 'device_model_id');
    }
}
