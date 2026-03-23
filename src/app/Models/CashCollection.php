<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CashCollection extends Model
{
    use HasFactory;

    protected $table = 'cash_collections';

    protected $fillable = [
        'organization_id',
        'country_id',
        'region_id',
        'district_id',
        'city_id',
        'main_location_id',
        'charity_location_id',
        'amount',
        'collected_by_user_id',
        'witness_name',
        'collector_signature_path',
        'witness_signature_path',
        'collected_at',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'collected_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

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

    public function mainLocation()
    {
        return $this->belongsTo(MainLocation::class, 'main_location_id');
    }

    public function charityLocation()
    {
        return $this->belongsTo(CharityLocation::class, 'charity_location_id');
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by_user_id');
    }
}