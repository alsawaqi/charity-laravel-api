<?php

namespace App\Http\Controllers;

 
use App\Models\District;
use Illuminate\Http\Request;

class DistrictController extends Controller
{
         public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $sortDir  = $request->query('sortDir', 'desc');
        $perPage  = (int) $request->query('per_page', 10);

        $countryId = $request->query('country_id');
        $regionId  = $request->query('region_id');

        // whitelist sort columns
        if (! in_array($sortBy, ['id', 'name', 'created_at'])) {
            $sortBy = 'id';
        }

        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $query = District::query()
            ->with(['region.country']); // so show() + table have region + country ready

        // Search by district name
        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by country (via region.country_id)
        if ($countryId) {
            $query->whereHas('region', function ($q) use ($countryId) {
                $q->where('country_id', $countryId);
            });
        }

        // Filter by region
        if ($regionId) {
            $query->where('region_id', $regionId);
        }

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'region_id' => ['required', 'exists:regions,id'],
            'name'      => ['required', 'string', 'max:255'],
        ]);

        $district = District::create($validated);

        return response()->json($district, 201);
    }

    public function show(District $district)
    {
        $district->load(['region.country']);

        return response()->json($district);
    }

    public function update(Request $request, District $district)
    {
        $validated = $request->validate([
            'region_id' => ['required', 'exists:regions,id'],
            'name'      => ['required', 'string', 'max:255'],
        ]);

        $district->update($validated);

        return response()->json($district);
    }

    public function destroy(District $district)
    {
        $district->delete();

        return response()->json([
            'message' => 'District deleted successfully',
        ]);
    }
}
