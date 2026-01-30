<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Company extends Model
{


    protected $table = 'companies';

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'notes', // optional if you added
        'is_active', // optional if you added
    ];

      public function activities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class)
            ->withTimestamps(); // pivot has timestamps
    }

    public function mainLocations(): HasMany
    {
        return $this->hasMany(MainLocation::class);
    }

    // Convenient: company -> main_locations -> charity_locations
    public function charityLocations(): HasManyThrough
    {
        return $this->hasManyThrough(
            CharityLocation::class,
            MainLocation::class,
            'company_id',        // FK on main_locations
            'main_location_id',  // FK on charity_locations
            'id',                // Company PK
            'id'                 // MainLocation PK
        );
    }

    public function charityTransactions(): HasMany
    {
        return $this->hasMany(CharityTransactions::class);
    }
}
