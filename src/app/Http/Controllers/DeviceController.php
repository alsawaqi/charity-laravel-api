<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use App\Models\MainLocation;
use Illuminate\Http\Request;
use App\Models\CharityLocation;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Services\ScalefusionService;

class DeviceController extends Controller
{
   public function index(Request $request)
    {
        $search  = $request->query('search');
        $sortBy  = $request->query('sortBy', 'id');

        // ✅ default should be asc if you want oldest first
        $sortDir = $request->query('sortDir', 'asc');

        $perPage = (int) $request->query('per_page', 10);

        $status    = $request->query('status');
        $countryId = $request->query('country_id');
        $brandId   = $request->query('device_brand_id');

        try {
            $query = Devices::query()
                ->with([
                    'deviceBrand',
                    'deviceModel',
                    'bank',
                    'country',
                    'region',
                    'district',
                    'city',
                    'charityLocation',
                    'commissionProfile',
                    'company:id,name',
                    'mainLocation:id,name,company_id',
                    'mainLocation.company:id,name',
                ]);

            if ($status) $query->where('status', $status);
            if ($countryId) $query->where('country_id', $countryId);
            if ($brandId) $query->where('device_brand_id', $brandId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('kiosk_id', 'like', "%{$search}%")
                    ->orWhere('model_number', 'like', "%{$search}%")
                    ->orWhere('login_generated_token', 'like', "%{$search}%");
                });
            }

            if (!in_array($sortBy, ['id', 'kiosk_id', 'installed_at', 'status', 'created_at'], true)) {
                $sortBy = 'id';
            }

            // ✅ correct validation: only allow asc/desc
            $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

            $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

            $withSf = filter_var($request->query('with_scalefusion', false), FILTER_VALIDATE_BOOL);

            if ($withSf) {
                $ids = $paginator->getCollection()
                    ->pluck('kiosk_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $sfMap = app(\App\Services\ScalefusionService::class)->findDevicesByIds($ids);

                $paginator->getCollection()->transform(function ($device) use ($sfMap) {
                    $key = (string) $device->kiosk_id;
                    $device->setAttribute('scalefusion', $sfMap[$key] ?? null);
                    return $device;
                });
            }

            return response()->json($paginator);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid query parameters: ' . $e->getMessage()], 400);
        }
    }


    public function show(Devices $device)
    {
        return response()->json(
            $device->load([
                'deviceBrand',
                'deviceModel',
                'bank',
                'country',
                'district',
                'region',
                'city',
                'charityLocation',
                'commissionProfile',
                'company:id,name',                 // ✅ NEW
                'mainLocation:id,name,company_id', // ✅ NEW
                'mainLocation.company:id,name',
            ])
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_brand_id'       => ['required', 'exists:device_brands,id'],
            'device_model_id'       => ['required', 'exists:device_models,id'],
            'bank_id'               => ['nullable', 'exists:banks,id'],
            'model_number'          => ['nullable', 'string', 'max:255'],

            'country_id'            => ['required', 'exists:countries,id'],
            'region_id'             => ['nullable', 'exists:regions,id'],
            'district_id'           => ['nullable', 'exists:districts,id'],
            'city_id'               => ['nullable', 'exists:cities,id'],

            'main_location_id'      => ['nullable', 'exists:main_locations,id'], // ✅ NEW
            'companies_id'          => ['nullable', 'exists:companies,id'],      // ✅ NEW

            'charity_location_id'   => ['nullable', 'exists:charity_locations,id'],
            'commission_profile_id' => ['nullable', 'exists:commission_profiles,id'],

            'kiosk_id'              => ['nullable', 'string', 'max:255'],
            'login_generated_token' => ['nullable', 'string', 'max:100', 'unique:devices,login_generated_token'],

            'status'                => ['nullable', 'string', Rule::in(['active', 'disabled', 'maintenance'])],
            'installed_at'          => ['nullable', 'date'],
        ]);

        if (empty($validated['status'])) $validated['status'] = 'active';

        // ✅ If charity location selected but main_location_id not sent, infer it
        if (!empty($validated['charity_location_id']) && empty($validated['main_location_id'])) {
            $cl = CharityLocation::select('id', 'main_location_id')->find($validated['charity_location_id']);
            if ($cl) $validated['main_location_id'] = $cl->main_location_id;
        }

        // ✅ Always set companies_id from main_location_id (source of truth)
        if (!empty($validated['main_location_id'])) {
            $ml = MainLocation::select('id', 'company_id')->find($validated['main_location_id']);
            if ($ml) $validated['companies_id'] = $ml->company_id; // overrides any mismatch
        }

        $device = Devices::create($validated);

        return response()->json(
            $device->load([
                'deviceBrand',
                'deviceModel',
                'bank',
                'country',
                'district',
                'region',
                'city',
                'charityLocation',
                'commissionProfile',
                'company:id,name',
                'mainLocation:id,name,company_id',
                'mainLocation.company:id,name',
            ]),
            201
        );
    }

    public function update(Request $request, Devices $device)
    {
        $validated = $request->validate([
            'device_brand_id'       => ['required', 'exists:device_brands,id'],
            'device_model_id'       => ['required', 'exists:device_models,id'],
            'bank_id'               => ['nullable', 'exists:banks,id'],
            'model_number'          => ['nullable', 'string', 'max:255'],

            'country_id'            => ['required', 'exists:countries,id'],
            'region_id'             => ['nullable', 'exists:regions,id'],
            'district_id'           => ['nullable', 'exists:districts,id'],
            'city_id'               => ['nullable', 'exists:cities,id'],

            'main_location_id'      => ['nullable', 'exists:main_locations,id'], // ✅ NEW
            'companies_id'          => ['nullable', 'exists:companies,id'],      // ✅ NEW

            'charity_location_id'   => ['nullable', 'exists:charity_locations,id'],
            'commission_profile_id' => ['nullable', 'exists:commission_profiles,id'],

            'kiosk_id'              => ['nullable', 'string', 'max:255'],
            'login_generated_token' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('devices', 'login_generated_token')->ignore($device->id),
            ],

            'status'                => ['nullable', 'string', Rule::in(['active', 'disabled', 'maintenance'])],
            'installed_at'          => ['nullable', 'date'],
        ]);

        if (empty($validated['status'])) $validated['status'] = $device->status ?? 'active';

        if (!empty($validated['charity_location_id']) && empty($validated['main_location_id'])) {
            $cl = CharityLocation::select('id', 'main_location_id')->find($validated['charity_location_id']);
            if ($cl) $validated['main_location_id'] = $cl->main_location_id;
        }

        if (!empty($validated['main_location_id'])) {
            $ml = MainLocation::select('id', 'company_id')->find($validated['main_location_id']);
            if ($ml) $validated['companies_id'] = $ml->company_id;
        }

        $device->update($validated);

        

        return response()->json(
            $device->load([
                'deviceBrand',
                'deviceModel',
                'bank',
                'country',
                'district',
                'region',
                'city',
                'charityLocation',
                'commissionProfile',
                'company:id,name',
                'mainLocation:id,name,company_id',
                'mainLocation.company:id,name',
            ])
        );
    }

    /**
     * Delete a device.
     */
    public function destroy(Devices $device)
    {
        try {
            $device->delete();

            return response()->json([
                'message' => 'Device deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
