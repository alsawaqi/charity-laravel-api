<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\CharityTransactions;

class CharityLocationStatusController extends Controller
{
 public function filters()
{
    // Country -> Regions -> Cities -> CharityLocations
    $countries = Country::with(['regions.cities.charityLocations'])
        ->orderBy('name') // adjust column if your column is different
        ->get();

    $data = $countries->map(function ($country) {
        return [
            'id'   => $country->id,
            'name' => $country->name,
            'regions' => $country->regions->map(function ($region) {
                return [
                    'id'   => $region->id,
                    'name' => $region->name,
                    'cities' => $region->cities->map(function ($city) {
                        return [
                            'id'   => $city->id,
                            'name' => $city->name,
                            'charity_locations' => $city->charityLocations->map(function ($loc) {
                                return [
                                    'id'   => $loc->id,
                                    'name' => $loc->name,
                                ];
                            })->values(),
                        ];
                    })->values(),
                ];
            })->values(),
        ];
    })->values();

    return response()->json([
        'data' => $data,
    ]);
}


    /**
     * Status by single charity_location (like device status).
     *
     * Params:
     *  - charity_location_id (required)
     *  - range: 7d | 30d | 6m | custom
     *  - from, to (YYYY-MM-DD) for custom
     */
    public function index(Request $request)
{
    $locationId = $request->integer('charity_location_id');

    if (!$locationId) {
        return response()->json([
            'message' => 'charity_location_id is required',
        ], 422);
    }

    $range = $request->input('range', '7d'); // 7d, 30d, 6m, custom
    $from  = $request->input('from');
    $to    = $request->input('to');

    $end = Carbon::today()->endOfDay();

    switch ($range) {
        case '30d':
            $start = $end->copy()->subDays(29)->startOfDay();
            break;

        case '6m':
            $start = $end->copy()->subMonthsNoOverflow(6)->startOfDay();
            break;

        case 'custom':
            if (!$from || !$to) {
                return response()->json([
                    'message' => 'From and to dates are required for custom range',
                ], 422);
            }
            $start = Carbon::parse($from)->startOfDay();
            $end   = Carbon::parse($to)->endOfDay();
            break;

        case '7d':
        default:
            $start = $end->copy()->subDays(6)->startOfDay();
            break;
    }

    // 🔹 Eager-load location correctly based on your migrations
    $base = CharityTransactions::with([
            'charityLocation.country',  // charity_locations.country_id
            'charityLocation.region',   // charity_locations.region_id
            'charityLocation.city',     // charity_locations.city_id
            'device.DeviceModel.DeviceBrand',
            'bank',
        ])
        ->where('charity_location_id', $locationId)
        ->whereBetween('created_at', [$start, $end]);

    // Totals
    $successQuery = (clone $base)->where('status', 'success');
    $failedQuery  = (clone $base)->where('status', 'fail');

    $successTotal = (clone $successQuery)->sum('total_amount');
    $failedTotal  = (clone $failedQuery)->sum('total_amount');

    // Successful transactions
    $successTransactions = $successQuery
        ->orderByDesc('created_at')
        ->get();

    // Failed transactions
    $failedTransactions = $failedQuery
        ->orderByDesc('created_at')
        ->get();

    return response()->json([
        'totals' => [
            'success' => (float) $successTotal,
            'failed'  => (float) $failedTotal,
        ],
        'success_transactions' => $successTransactions,
        'failed_transactions'  => $failedTransactions,
    ]);
}

}
