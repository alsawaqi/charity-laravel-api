<?php

namespace App\Http\Controllers;

use App\Models\CharityTransactions;
use Illuminate\Http\Request;

class CharityTransactionsController extends Controller
{
    //


    public function store(Request $request)
    {

        $charity =   CharityTransactions::create([
            'device_id' => 1,
            'commission_profile_id' => 1,
            'total_amount' => $request->input('amount'),
            'bank_response' => $request->input('receipt') ? json_encode($request->input('receipt')) : null,
            'bank_transaction_id' => 1,
            'reference' => $request->input('id'),
            'status' => $request->input('status'),
        ]);


        return response()->json([
            'success' => true,
            'data'    => $charity,
            'message' => 'charity successfully',
        ], 201);
    }
}
