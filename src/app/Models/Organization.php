<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'organizations';

       protected $fillable = [
        'name',
        'trade_name',
        'cr_number',
        'tax_number',
        'phone',
        'email',
        'website',
        'country_id',
        'region_id',
        'city_id',
        'address_line1',
        'address_line2',
        'postal_code',
        'bank_id',
        'bank_account_name',
        'iban',
        'account_number',
        'swift_code',
        'is_active',
        'notes',
        'parent_id',
    ];



      public function parent()
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    public function charityLocations()
    {
        return $this->hasMany(CharityLocation::class, 'organization_id');
    }

     public function users()
    {
        return $this->hasMany(User::class);
    }


     public function primaryUser()
    {
        return $this->hasOne(User::class)->oldest();
    }

     public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get IDs of this organization + all descendants (for filtering).
     */
    public function descendantsAndSelfIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->descendantsAndSelfIds());
        }

        return $ids;
    }
}
