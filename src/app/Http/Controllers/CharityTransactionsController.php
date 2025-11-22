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
    public function index()
    {
        $transactions = CharityTransactions::with(['device',  'device.devicemodel', 'charityLocation', 'bank', 'charitytransactionshares', 'charitytransactionshares.comissionProfileShare', 'charitytransactionshares.comissionProfileShare.organization'])->get();

        return response()->json([
            'success' => true,
            'data'    => $transactions,
            'message' => 'Charity transactions retrieved successfully.',
        ], 200);
    }


    public function store(Request $request)
    {

        try {
            $result = DB::transaction(function () use ($request) {
                $device = Devices::where('kiosk_id', $request->input('id'))->first();

                $commissionProfile = CommissionProfiles::where('id', $device->commission_profile_id)->first();

                $commissionProfileShares = CommissionProfilesShares::where('commission_profile_id', $commissionProfile->id)->get();


                $rawReceipt = $request->input('receipt');

                // Normalize: if it's already an array (from JSON body), use it,
                // if it's a JSON string, decode it.
                if (is_string($rawReceipt)) {
                    $receipt = json_decode($rawReceipt, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $receipt = null; // or handle error
                    }
                } elseif (is_array($rawReceipt)) {
                    $receipt = $rawReceipt;
                } else {
                    $receipt = null;
                }

                // Default
                $status = 'fail';

                if ($receipt && isset($receipt['reason'])) {
                    // Normalize to upper to be safe
                    $reason = strtoupper((string) $receipt['reason']);

                    if ($reason === 'SUCCESS') {
                        $status = 'success';
                    }
                }




                $charity =   CharityTransactions::create([
                    'device_id' => $device->id,
                    'commission_profile_id' =>  $commissionProfile->id,
                    'total_amount' => $request->input('amount'),
                    'bank_response' => $receipt,
                    'bank_transaction_id' => $device->bank_id,

                    'status' => $request->input('status'),
                    'country_id' => $device->country_id,
                    'region_id' => $device->region_id,
                    'city_id' => $device->city_id,
                    'charity_location_id' => $device->charity_location_id,
                    'latitude' => $request->input('latitude') ?? 0.00,
                    'longitude' => $request->input('longitude') ?? 0.00,
                ]);

                $shareRows = [];

                foreach ($commissionProfileShares as $share) {
                    $percentage = (float) $share->percentage; // or $share->percentage
                    $shareAmount = round($request->input('amount') * $percentage / 100, 3); // round as you like

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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing charity transaction: ' . $e->getMessage(),
            ], 500);
        }
    }
}
