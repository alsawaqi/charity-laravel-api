<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharityTransactionShare extends Model
{
    protected $table = 'charity_transaction_shares';

    protected $guarded = [];


    public function charityTransaction()
    {
        return $this->belongsTo(CharityTransactions::class, 'charity_transaction_id');
    }

    public function comissionProfileShare()
    {
        return $this->belongsTo(CommissionProfilesShares::class, 'commission_profile_share_id');
    }
}
