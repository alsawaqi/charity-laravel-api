<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Banks extends Model
{
      use HasFactory;

    protected $table = 'banks';

    protected $fillable = [
        'name',
        'short_name',
        'country_id',
        'swift_code',
        'iban_example',
        'branch_name',
        'phone',
        'email',
        'website',
        'is_active',
        'notes',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }
}