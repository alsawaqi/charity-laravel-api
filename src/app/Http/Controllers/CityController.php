<?php

namespace App\Http\Controllers;

use App\Models\City;
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
        $regionId   = $request->query('region_id');
        $countryId  = $request->query('country_id'); // optional filter by country

        $query = City::query()->with(['region.country']);

        // optional filters
        if ($regionId) {
            $query->where('region_id', $regionId);
        }

        if ($countryId) {
            $query->whereHas('region', function ($q) use ($countryId) {
                $q->where('country_id', $countryId);
            });
        }

        // search by city name / postal code
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('postal_code', 'like', "%{$search}%");
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'name', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'region_id'   => ['required', 'exists:regions,id'],
            'name'        => [
                'required',
                'string',
                'max:255',
                // unique per region
                Rule::unique('cities', 'name')->where('region_id', $request->region_id),
            ],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        if (! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }

        $city = City::create($data);

        return response()->json($city, 201);
    }

    public function show(City $city)
    {
        $city->load('region.country');

        return response()->json($city);
    }

    public function update(Request $request, City $city)
    {
        $data = $request->validate([
            'region_id'   => ['required', 'exists:regions,id'],
            'name'        => [
                'required',
                'string',
                'max:255',
                Rule::unique('cities', 'name')
                    ->where('region_id', $request->region_id)
                    ->ignore($city->id),
            ],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $city->update($data);

        return response()->json($city);
    }

    public function destroy(City $city)
    {
        $city->delete();

        return response()->json([
            'message' => 'City deleted successfully',
        ]);
    }
}
