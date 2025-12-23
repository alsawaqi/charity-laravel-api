<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Devices;
use Illuminate\Http\Request;
use App\Services\ScalefusionService;

class DeviceLocationController extends Controller
{
    /**
     * Nested filters:
     * Country -> Region -> District -> City -> MainLocation
     */
    public function filters()
    {
        $countries = Country::with([
            'regions.districts.cities.mainLocations.company', // company optional
        ])
        ->orderBy('name')
        ->get();

        $data = $countries->map(function ($country) {
            return [
                'id' => $country->id,
                'name' => $country->name,
                'regions' => $country->regions->map(function ($region) {
                    return [
                        'id' => $region->id,
                        'name' => $region->name,
                        'districts' => $region->districts->map(function ($district) {
                            return [
                                'id' => $district->id,
                                'name' => $district->name,
                                'cities' => $district->cities->map(function ($city) {
                                    return [
                                        'id' => $city->id,
                                        'name' => $city->name,
                                        'main_locations' => $city->mainLocations->map(function ($ml) {
                                            return [
                                                'id' => $ml->id,
                                                'name' => $ml->name,
                                                'company_id' => $ml->company_id ?? null,
                                                'company' => $ml->company ? [
                                                    'id' => $ml->company->id,
                                                    'name' => $ml->company->name,
                                                ] : null,
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
    }

    /**
     * Devices list filtered by ANY selected level:
     * country_id / region_id / district_id / city_id / main_location_id + search
     * Optionally attaches scalefusion data + returns summary totals.
     */
    public function devices(Request $request, ScalefusionService $sf)
    {
        $search  = $request->query('search');
        $sortBy  = $request->query('sortBy', 'id');
        $sortDir = $request->query('sortDir', 'desc');
        $perPage = (int) $request->query('per_page', 10);

        $status = $request->query('status');

        $countryId = $request->query('country_id');
        $regionId = $request->query('region_id');
        $districtId = $request->query('district_id');
        $cityId = $request->query('city_id');
        $mainLocationId = $request->query('main_location_id');

        $query = Devices::query()
            ->with([
                'deviceBrand',
                'deviceModel',
                'bank',
                'country',
                'region',
                'district',
                'city',
                'mainLocation.company',
            ]);

        // filters (ANY level works)
        if ($status) $query->where('status', $status);
        if ($countryId) $query->where('country_id', $countryId);
        if ($regionId) $query->where('region_id', $regionId);
        if ($districtId) $query->where('district_id', $districtId);
        if ($cityId) $query->where('city_id', $cityId);
        if ($mainLocationId) $query->where('main_location_id', $mainLocationId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('kiosk_id', 'like', "%{$search}%")
                  ->orWhere('model_number', 'like', "%{$search}%")
                  ->orWhere('login_generated_token', 'like', "%{$search}%");
            });
        }

        if (!in_array($sortBy, ['id','kiosk_id','installed_at','status','created_at'], true)) {
            $sortBy = 'id';
        }
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $withSf = filter_var($request->query('with_scalefusion', true), FILTER_VALIDATE_BOOL);
        $withSfSummary = filter_var($request->query('with_scalefusion_summary', true), FILTER_VALIDATE_BOOL);

        // paginate first
        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        // attach scalefusion data to THIS PAGE
        if ($withSf) {
            $pageIds = $paginator->getCollection()
                ->pluck('kiosk_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $sfMap = $sf->findDevicesByIds($pageIds);

            $paginator->getCollection()->transform(function ($device) use ($sfMap) {
                $key = (string) $device->kiosk_id;
                $device->setAttribute('scalefusion', $sfMap[$key] ?? null);
                return $device;
            });
        }

        // summary (TOTALS across ALL filtered devices, not only this page)
        $summary = null;
        if ($withSfSummary) {
            $allIds = (clone $query)->pluck('kiosk_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $sfMapAll = $sf->findDevicesByIds($allIds);

            $total = count($allIds);

            $online = 0;
            $offline = 0;
            $charging = 0;
            $unknown = 0;

            foreach ($allIds as $id) {
                $info = $sfMapAll[(string)$id] ?? null;
                if (!$info) {
                    $unknown++;
                    continue;
                }

                if (!empty($info['battery_charging'])) $charging++;

                $cs = $info['connection_status'] ?? null;
                if ($cs === 'Online') $online++;
                elseif ($cs) $offline++;
                else $unknown++;
            }

            $summary = [
                'total_devices' => $total,
                'online_devices' => $online,
                'offline_devices' => $offline,
                'charging_devices' => $charging,
                'unknown_devices' => $unknown,
            ];
        }

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'scalefusion_summary' => $summary,
        ]);
    }
}
