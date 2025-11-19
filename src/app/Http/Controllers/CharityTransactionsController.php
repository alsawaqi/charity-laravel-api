<?php

namespace App\Http\Controllers;

use App\Models\CharityTransactions;
use Illuminate\Http\Request;

class CharityTransactionsController extends Controller
{
    //


    public function store(Request $request)
    {  

          CharityTransactions::create([
                              'device_id' => 1,
                              'commission_profile_id' => 1,
                              'amount' => $request->input('amount'),
                              'bank_transaction_id' => 1,
                ]);
      
    }   
}
