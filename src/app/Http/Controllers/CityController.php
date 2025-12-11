<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

 class CityController extends Controller
{
    public function index(Request $request)
    {
        $search     = $request->query('search');
        $sortBy     = $request->query('sortBy', 'id');
        $sortDir    = $request->query('sortDir', 'desc');
        $perPage    = (int) $request->query('per_page', 10);
        $countryId  = $request->query('country_id');
        $regionId   = $request->query('region_id');
        $districtId = $request->query('district_id');
        $isActive   = $request->query('is_active'); // optional

        if (! in_array($sortBy, ['id', 'name', 'created_at'])) {
            $sortBy = 'id';
        }

        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $query = City::query()
            ->with([
                'region.country',
                'district.region.country',
            ]);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if (! is_null($isActive)) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if ($countryId) {
            $query->whereHas('region', function ($q) use ($countryId) {
                $q->where('country_id', $countryId);
            });
        }

        if ($regionId) {
            $query->where('region_id', $regionId);
        }

        if ($districtId) {
            $query->where('district_id', $districtId);
        }

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'region_id'   => ['required', 'exists:regions,id'],
            'district_id' => ['required', 'exists:districts,id'],
            'name'        => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'is_active'   => ['boolean'],
        ]);

        // Ensure district belongs to region
        $districtRegionId = District::whereKey($validated['district_id'])->value('region_id');
        if ($districtRegionId && (int) $districtRegionId !== (int) $validated['region_id']) {
            return response()->json([
                'message' => 'Selected district does not belong to the selected region.',
            ], 422);
        }

        $city = City::create($validated);

        return response()->json(
            $city->load(['region.country', 'district.region.country']),
            201
        );
    }

    public function show(City $city)
    {
        $city->load(['region.country', 'district.region.country']);

        return response()->json($city);
    }

    public function update(Request $request, City $city)
    {
        $validated = $request->validate([
            'region_id'   => ['required', 'exists:regions,id'],
            'district_id' => ['required', 'exists:districts,id'],
            'name'        => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'is_active'   => ['boolean'],
        ]);

        try{

       

        $districtRegionId = District::whereKey($validated['district_id'])->value('region_id');
        if ($districtRegionId && (int) $districtRegionId !== (int) $validated['region_id']) {
            return response()->json([
                'message' => 'Selected district does not belong to the selected region.',
            ], 422);
        }

        $city->update($validated);

        return response()->json(
            $city->load(['region.country', 'district.region.country'])
        );
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'An error occurred while updating the city.',
            'error' => $e->getMessage(),            
        ], 500);
      }
    }

    public function destroy(City $city)
    {
        $city->delete();

        return response()->json([
            'message' => 'City deleted successfully',
        ]);
    }
}
