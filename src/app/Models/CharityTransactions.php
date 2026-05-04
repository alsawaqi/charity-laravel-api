<?php

namespace App\Models;

use App\Events\CharityTransactionCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CharityTransactions extends Model
{
    //

    protected $table = 'charity_transactions';

    protected $guarded = [];

    protected $casts = [
        'bank_response' => 'array',   // 👈 add this
    ];


    protected static function booted(): void
    {
        static::created(function (CharityTransactions $transaction): void {
            DB::afterCommit(function () use ($transaction): void {
                event(new CharityTransactionCreated($transaction->fresh() ?? $transaction));
            });
        });
    }

    public function device()
    {
        return $this->belongsTo(Devices::class, 'device_id');
    }

    public function charitytransactionshares()
    {
        return $this->hasMany(CharityTransactionShare::class, 'charity_transaction_id');
    }

    public function commissionProfile()
    {
        return $this->belongsTo(CommissionProfiles::class, 'commission_profile_id');
    }

    public function bank()
    {
        return $this->belongsTo(Banks::class, 'bank_transaction_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }
    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_id');
    }
    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function charityLocation()
    {
        return $this->belongsTo(CharityLocation::class, 'charity_location_id');
    }


     public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function mainLocation(): BelongsTo
    {
        return $this->belongsTo(MainLocation::class, 'main_location_id');
    }
 
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
 
}
