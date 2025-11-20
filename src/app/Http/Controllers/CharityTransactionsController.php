<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use Illuminate\Http\Request;
use App\Models\CommissionProfiles;
use Illuminate\Support\Facades\DB;
use App\Models\CharityTransactions;
use App\Models\CharityTransactionShare;
use App\Models\CommissionProfilesShares;

class CharityTransactionsController extends Controller
{
    //


    public function store(Request $request)
    {

        try{
             $result = DB::transaction(function () use ($request) {
        $device = Devices::where('kiosk_id', $request->input('id'))->first();

        $commissionProfile = CommissionProfiles::where('id', $device->commission_profile_id)->first();

        $commissionProfileShares = CommissionProfilesShares::where('commission_profile_id', $commissionProfile->id)->get();
   
         

       

        $charity =   CharityTransactions::create([
            'device_id' => $device->id,
            'commission_profile_id' =>  $commissionProfile->id,
            'total_amount' => $request->input('amount'),
            'bank_response' => $request->input('receipt') ? json_encode($request->input('receipt')) : null,
            'bank_transaction_id' => 1,
        
            'status' => $request->input('status'),
        ]);

   $shareRows = [];

         foreach ($commissionProfileShares as $share) {
                $percentage = (float) $share->percentage; // or $share->percentage
                $shareAmount = round($$request->input('amount') * $percentage / 100, 3); // round as you like

                $shareRows[] = CharityTransactionShare::create([
                    'charity_transaction_id'      => $charity->id,
                   
                    'commission_profile_share_id' => $share->id,
            
                    'amount'    => $shareAmount,
                ]);
            }

      return [
                'charity' => $charity,
                'shares'  => $shareRows,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $result,
            'message' => 'Charity transaction stored and shares calculated successfully.',
        ], 201);


          }catch(\Exception $e){
            return response()->json([
                'success' => false,
                'message' => 'Error processing charity transaction: ' . $e->getMessage(),
            ], 500);
         }
    }
}
