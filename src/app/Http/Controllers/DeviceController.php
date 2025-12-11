<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    /**
     * List devices (with filters + pagination).
     */
    public function index(Request $request)
    {
        $search         = $request->query('search');
        $sortBy         = $request->query('sortBy', 'id');
        $sortDir        = $request->query('sortDir', 'desc');
        $perPage        = (int) $request->query('per_page', 10);

        $status         = $request->query('status');          // active, disabled, maintenance
        $countryId      = $request->query('country_id');
        $brandId        = $request->query('device_brand_id');

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
                ]);

            // Filters
            if ($status) {
                $query->where('status', $status);
            }

            if ($countryId) {
                $query->where('country_id', $countryId);
            }

            if ($brandId) {
                $query->where('device_brand_id', $brandId);
            }

            // Search on kiosk_id & model_number
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('kiosk_id', 'like', "%{$search}%")
                      ->orWhere('model_number', 'like', "%{$search}%");
                });
            }

            // Whitelist sortable columns
            if (! in_array($sortBy, ['id', 'kiosk_id', 'installed_at', 'status', 'created_at'], true)) {
                $sortBy = 'id';
            }

            $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

            $query->orderBy($sortBy, $sortDir);

            return response()->json(
                $query->paginate($perPage)
            );
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show one device with all relations.
     */
    public function show(Devices $device)
    {
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
        ]);

        return response()->json($device);
    }

    /**
     * Store a new device.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_brand_id'       => ['required', 'exists:device_brands,id'],
            'device_model_id'       => ['required', 'exists:device_models,id'],
            'bank_id'               => ['nullable', 'exists:banks,id'],

            'model_number'          => ['nullable', 'string', 'max:255'],

            'country_id'            => ['required', 'exists:countries,id'],
            'region_id'             => ['nullable', 'exists:regions,id'],
            'city_id'               => ['nullable', 'exists:cities,id'],
            'district_id'           => ['nullable', 'exists:districts,id'],
            'charity_location_id'   => ['nullable', 'exists:charity_locations,id'],

            'commission_profile_id' => ['nullable', 'exists:commission_profiles,id'],

            'kiosk_id'              => ['nullable', 'string', 'max:255'],
            'login_generated_token' => ['nullable', 'string', 'max:100', 'unique:devices,login_generated_token'],

            'status'                => ['nullable', 'string', Rule::in(['active', 'disabled', 'maintenance'])],
            'installed_at'          => ['nullable', 'date'],
        ]);

        // default status if not provided
        if (! array_key_exists('status', $validated) || ! $validated['status']) {
            $validated['status'] = 'active';
        }

        try {
            $device = Devices::create($validated);

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
            ]);

            return response()->json($device, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing device.
     */
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

        if (! array_key_exists('status', $validated) || ! $validated['status']) {
            $validated['status'] = $device->status ?? 'active';
        }

        try {
            $device->update($validated);

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
            ]);

            return response()->json($device);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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
