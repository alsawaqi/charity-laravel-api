<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Activity extends Model
{
    protected $fillable = [

        'name',
        'is_active',

    ];

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withTimestamps();
    }
}
