<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'organizations';

    protected $guarded = [];



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
