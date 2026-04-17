<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Devices;
use Illuminate\Http\Request;
 
use App\Models\CharityTransactions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Http\Controllers\Concerns\ResolvesCharityReportFilters;

class CharityLocationStatusController extends Controller
{
    use ResolvesCharityReportFilters;

    public function filters()
    {
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

    private function paginatorMeta(LengthAwarePaginator $paginator): array
    {
            return [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ];
    }

    public function index(Request $request)
    {
        try {
            $countryId = $request->integer('country_id');
            $regionId = $request->integer('region_id');
            $districtId = $request->integer('district_id');
            $cityId = $request->integer('city_id');
            $mainLocationId = $request->integer('main_location_id');
            $charityLocationId = $request->integer('charity_location_id');

            if (!$countryId && !$regionId && !$districtId && !$cityId && !$mainLocationId && !$charityLocationId) {
                return response()->json([
                    'message' => 'At least one location filter is required (country/region/district/city/main_location/charity_location).',
                ], 422);
            }

            try {
                ['start' => $start, 'end' => $end] = $this->resolveCharityRangeFromRequest($request);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Invalid date range. Use YYYY-MM-DD.',
                ], 422);
            }

            $endExclusive = $end->copy()->addDay()->startOfDay();

            $scopeType = null;
            $scopeId = null;

            if ($charityLocationId) {
                $scopeType = 'charity_location';
                $scopeId = $charityLocationId;
            } elseif ($mainLocationId) {
                $scopeType = 'main_location';
                $scopeId = $mainLocationId;
            } elseif ($cityId) {
                $scopeType = 'city';
                $scopeId = $cityId;
            } elseif ($districtId) {
                $scopeType = 'district';
                $scopeId = $districtId;
            } elseif ($regionId) {
                $scopeType = 'region';
                $scopeId = $regionId;
            } elseif ($countryId) {
                $scopeType = 'country';
                $scopeId = $countryId;
            }

            $successPage = max(1, (int) $request->input('success_page', 1));
            $failedPage = max(1, (int) $request->input('failed_page', 1));

            $successPerPage = min(100, max(5, (int) $request->input('success_per_page', 10)));
            $failedPerPage = min(100, max(5, (int) $request->input('failed_per_page', 10)));

            $base = CharityTransactions::query()
                ->with([
                    'country:id,name',
                    'region:id,name',
                    'district:id,name',
                    'city:id,name',
                    'mainLocation:id,name',
                    'charityLocation:id,name,main_location_id',
                    'device.DeviceModel.DeviceBrand',
                    'bank:id,name',
                    'company:id,name',
                    'organization:id,name',
                ])
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $endExclusive);

            // Apply all selected location filters directly on charity_transactions snapshot columns
            if ($countryId) {
                $base->where('country_id', $countryId);
            }
            if ($regionId) {
                $base->where('region_id', $regionId);
            }
            if ($districtId) {
                $base->where('district_id', $districtId);
            }
            if ($cityId) {
                $base->where('city_id', $cityId);
            }
            if ($mainLocationId) {
                $base->where('main_location_id', $mainLocationId);
            }
            if ($charityLocationId) {
                $base->where('charity_location_id', $charityLocationId);
            }

            $successQuery = (clone $base)->whereIn('status', $this->charitySuccessStatuses());
            $failedQuery = (clone $base)->whereIn('status', $this->charityFailedStatuses());

            $successTotal = (float) (clone $successQuery)->sum('total_amount');
            $failedTotal = (float) (clone $failedQuery)->sum('total_amount');

            $successTransactions = (clone $successQuery)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($successPerPage, ['*'], 'success_page', $successPage);

            $failedTransactions = (clone $failedQuery)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($failedPerPage, ['*'], 'failed_page', $failedPage);

            $limit = max(1, (int) $request->input('top_devices_limit', 10));

            $topAgg = (clone $base)
            ->whereIn('status', $this->charitySuccessStatuses())
                ->whereNotNull('device_id')
                ->selectRaw('device_id, COUNT(*) as success_count, SUM(total_amount) as success_amount, MAX(created_at) as last_tx_at')
                ->groupBy('device_id')
                ->orderByDesc('success_count')
                ->limit($limit)
                ->get();

            $topDeviceIds = $topAgg->pluck('device_id')->filter()->values()->all();

            $devicesMap = Devices::query()
                ->with(['deviceBrand', 'deviceModel'])
                ->whereIn('id', $topDeviceIds)
                ->get()
                ->keyBy('id');

            $topDevices = $topAgg->map(function ($row) use ($devicesMap) {
                $dev = $devicesMap->get($row->device_id);

                return [
                    'device_id' => (int) $row->device_id,
                    'kiosk_id' => $dev?->kiosk_id,
                    'status' => $dev?->status,
                    'name' => $dev?->name ?? null,
                    'brand' => $dev?->deviceBrand?->name,
                    'model' => $dev?->deviceModel?->name,
                    'success_count' => (int) $row->success_count,
                    'success_amount' => (float) $row->success_amount,
                    'last_tx_at' => $row->last_tx_at,
                ];
            })->values();

                    $byHourRaw = (clone $base)
                        ->whereIn('status', $this->charitySuccessStatuses())
                        ->selectRaw('((EXTRACT(DOW FROM created_at)::int + 6) % 7) as weekday_index, EXTRACT(HOUR FROM created_at)::int as hour_of_day, SUM(total_amount) as total_amount')
                        ->groupBy('weekday_index', 'hour_of_day')
                        ->orderBy('weekday_index')
                        ->orderBy('hour_of_day')
                        ->get();

                    $weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

                    $matrix = [];
                    foreach (range(0, 23) as $hour) {
                        $matrix[$hour] = array_fill(0, 7, 0.0);
                    }

                    foreach ($byHourRaw as $row) {
                        $weekday = (int) $row->weekday_index;
                        $hour = (int) $row->hour_of_day;

                        if (isset($matrix[$hour][$weekday])) {
                            $matrix[$hour][$weekday] = (float) $row->total_amount;
                        }
                    }

                    $hourSeries = [];
                    foreach (range(0, 23) as $hour) {
                        $hourSeries[] = [
                            'name' => sprintf('%02d:00', $hour),
                            'data' => $matrix[$hour],
                        ];
                    }



             return response()->json([
                    'scope' => [
                        'type' => $scopeType,
                        'id' => $scopeId,
                    ],
                    'totals' => [
                        'success' => $successTotal,
                        'failed' => $failedTotal,
                    ],
                    'top_devices' => $topDevices,

                    'success_transactions' => [
                        'data' => $successTransactions->items(),
                        'meta' => $this->paginatorMeta($successTransactions),
                    ],

                    'failed_transactions' => [
                        'data' => $failedTransactions->items(),
                        'meta' => $this->paginatorMeta($failedTransactions),
                    ],

                    'sales_by_hour' => [
                        'categories' => $weekdayNames,
                        'series' => $hourSeries,
                    ],
                ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid query parameters: ' . $e->getMessage()], 400);
        }
    }
}
