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
        'name',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
