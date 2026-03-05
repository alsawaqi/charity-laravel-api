<?php

namespace App\Http\Controllers;

use App\Models\MainLocation;
use Illuminate\Http\Request;

class MainLocationController extends Controller
{
    public function index(Request $request)
    {
        $search  = $request->query('search');
        $sortBy  = $request->query('sortBy', 'id');
        $sortDir = $request->query('sortDir', 'desc');
        $perPage = (int) $request->query('per_page', 10);

        $query = MainLocation::query()
            ->with([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'organization:id,name',
                'district:id,name',
                'company:id,name',
            ]);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'name', 'created_at'])) {
            $sortBy = 'id';
        }

        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    /**
     * Simple list for dropdowns if needed (id + name).
     */
    public function listAll()
    {
        return response()->json(
            MainLocation::select(
                'id',
                'name',
                'organization_id',
                'company_id',
                'country_id',
                'region_id',
                'district_id',
                'city_id'
            )
                ->orderBy('name')
                ->get()
        );
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'country_id'      => ['required', 'exists:countries,id'],
            'region_id'       => ['nullable', 'exists:regions,id'],
            'city_id'         => ['nullable', 'exists:cities,id'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'district_id'    => ['nullable', 'exists:districts,id'],
              'company_id'      => ['nullable', 'exists:companies,id'], 
            'name'            => ['required', 'string', 'max:255'],
        ]);

        $mainLocation = MainLocation::create($validated);

        return response()->json(
            $mainLocation->load([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'district:id,name',
                'organization:id,name',
                'company:id,name', // ✅ add
            ]),
            201
        );
    }

    public function show(MainLocation $mainLocation)
    {
        return response()->json(
            $mainLocation->load([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'district:id,name',
                'organization:id,name',
                'company:id,name',
            ])
        );
    }

    public function update(Request $request, MainLocation $mainLocation)
    {
        $validated = $request->validate([
            'country_id'      => ['required', 'exists:countries,id'],
            'region_id'       => ['nullable', 'exists:regions,id'],
            'city_id'         => ['nullable', 'exists:cities,id'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'district_id'    => ['nullable', 'exists:districts,id'],
             'company_id'      => ['nullable', 'exists:companies,id'], 
            'name'            => ['required', 'string', 'max:255'],
        ]);

        $mainLocation->update($validated);

        return response()->json(
            $mainLocation->load([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'district:id,name',
                'organization:id,name',
                'company:id,name', 
            ])
        );
    }

    public function destroy(MainLocation $mainLocation)
    {
        $mainLocation->delete();

        return response()->json([
            'message' => 'Main location deleted successfully',
        ]);
    }
}
