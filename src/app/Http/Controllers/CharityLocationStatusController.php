<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Devices;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\CharityTransactions;

class CharityLocationStatusController extends Controller
{
    public function filters()
    {
        // Expected relations:
        // Country -> regions
        // Region  -> districts
        // District -> cities
        // City -> mainLocations
        // MainLocation -> charityLocations




        try {


            $countries = Country::with([
                'regions.districts.cities.mainLocations.charityLocations'
            ])->orderBy('name')->get();


            $data = $countries->map(function ($country) {
                return [
                    'id'   => $country->id,
                    'name' => $country->name,
                    'regions' => $country->regions->map(function ($region) {
                        return [
                            'id'   => $region->id,
                            'name' => $region->name,
                            'districts' => $region->districts->map(function ($district) {
                                return [
                                    'id'   => $district->id,
                                    'name' => $district->name,
                                    'cities' => $district->cities->map(function ($city) {
                                        return [
                                            'id'   => $city->id,
                                            'name' => $city->name,
                                            'main_locations' => $city->mainLocations->map(function ($ml) {
                                                return [
                                                    'id'   => $ml->id,
                                                    'name' => $ml->name,
                                                    'charity_locations' => $ml->charityLocations->map(function ($loc) {
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
                            })->values(),
                        ];
                    })->values(),
                ];
            })->values();

            return response()->json(['data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load filters: ' . $e->getMessage()], 500);
        }
    }



    public function index(Request $request)
{

     try {
    // ✅ Accept any scope (most specific wins)
    $countryId       = $request->integer('country_id');
    $regionId        = $request->integer('region_id');
    $districtId      = $request->integer('district_id');
    $cityId          = $request->integer('city_id');
    $mainLocationId  = $request->integer('main_location_id');
    $charityLocationId = $request->integer('charity_location_id');

    // ✅ Must select at least one
    if (!$countryId && !$regionId && !$districtId && !$cityId && !$mainLocationId && !$charityLocationId) {
        return response()->json([
            'message' => 'At least one location filter is required (country/region/district/city/main_location/charity_location).',
        ], 422);
    }

    // ---- Date range ----
    $range = $request->input('range', '7d');
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

    // ✅ Decide the final scope (most specific wins)
    $scopeType = null;
    $scopeId   = null;

    if ($charityLocationId) { $scopeType = 'charity_location'; $scopeId = $charityLocationId; }
    elseif ($mainLocationId){ $scopeType = 'main_location';   $scopeId = $mainLocationId; }
    elseif ($cityId)        { $scopeType = 'city';            $scopeId = $cityId; }
    elseif ($districtId)    { $scopeType = 'district';        $scopeId = $districtId; }
    elseif ($regionId)      { $scopeType = 'region';          $scopeId = $regionId; }
    elseif ($countryId)     { $scopeType = 'country';         $scopeId = $countryId; }

    // ---- Base query ----
    $base = CharityTransactions::with([
        'charityLocation.country',
        'charityLocation.region',
        'charityLocation.district',
        'charityLocation.city',
        'charityLocation.mainLocation',
        'device.DeviceModel.DeviceBrand',
        'bank',
    ])
    ->whereBetween('created_at', [$start, $end]);

    // ✅ Apply scope filter
    switch ($scopeType) {
        case 'charity_location':
            $base->where('charity_location_id', $scopeId);
            break;

        case 'main_location':
            $base->whereHas('charityLocation', fn($q) => $q->where('main_location_id', $scopeId));
            break;

        case 'city':
            $base->whereHas('charityLocation', fn($q) => $q->where('city_id', $scopeId));
            break;

        case 'district':
            $base->whereHas('charityLocation', fn($q) => $q->where('district_id', $scopeId));
            break;

        case 'region':
            $base->whereHas('charityLocation', fn($q) => $q->where('region_id', $scopeId));
            break;

        case 'country':
            $base->whereHas('charityLocation', fn($q) => $q->where('country_id', $scopeId));
            break;
    }

    // Totals
    $successQuery = (clone $base)->where('status', 'success');
    $failedQuery  = (clone $base)->where('status', 'fail');

    $successTotal = (clone $successQuery)->sum('total_amount');
    $failedTotal  = (clone $failedQuery)->sum('total_amount');

    // Lists
    $successTransactions = $successQuery->orderByDesc('created_at')->get();
    $failedTransactions  = $failedQuery->orderByDesc('created_at')->get();

    $limit = (int) $request->input('top_devices_limit', 10);

// ✅ Aggregate top devices from SUCCESS transactions only
$topAgg = (clone $base)
    ->where('status', 'success')
    ->whereNotNull('device_id')
    ->selectRaw('device_id, COUNT(*) as success_count, SUM(total_amount) as success_amount, MAX(created_at) as last_tx_at')
    ->groupBy('device_id')
    ->orderByDesc('success_count')
    ->limit($limit)
    ->get();

$topDeviceIds = $topAgg->pluck('device_id')->filter()->values()->all();

$devicesMap = Devices::query()
    ->with(['deviceBrand', 'deviceModel']) // same relations you already use
    ->whereIn('id', $topDeviceIds)
    ->get()
    ->keyBy('id');

// ✅ Build final array in same order as aggregation
$topDevices = $topAgg->map(function ($row) use ($devicesMap) {
    $dev = $devicesMap->get($row->device_id);

    return [
        'device_id'      => (int) $row->device_id,
        'kiosk_id'       => $dev?->kiosk_id,
        'status'         => $dev?->status, // your internal device status (active/disabled/maintenance)
        'name'           => $dev?->name ?? null, // if you have it
        'brand'          => $dev?->deviceBrand?->name,
        'model'          => $dev?->deviceModel?->name,
        'success_count'  => (int) $row->success_count,
        'success_amount' => (float) $row->success_amount,
        'last_tx_at'     => $row->last_tx_at,
    ];
})->values();

    return response()->json([
        'scope' => [
            'type' => $scopeType,
            'id'   => $scopeId,
        ],
        'totals' => [
            'success' => (float) $successTotal,
            'failed'  => (float) $failedTotal,
        ],
        'top_devices' => $topDevices,
        'success_transactions' => $successTransactions,
        'failed_transactions'  => $failedTransactions,
    ]);

    }catch(\Exception $e){
            return response()->json(['error' => 'Invalid query parameters: ' . $e->getMessage()], 400);
        }
}

}
