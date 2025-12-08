<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CharityLocation;

class CharityLocationController extends Controller
{
     public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $sortDir  = $request->query('sortDir', 'desc');
        $perPage  = (int) $request->query('per_page', 10);

        $query = CharityLocation::query()
            ->with([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'organization:id,name',
                'main_location:id,name',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        }

        if (! in_array($sortBy, ['id', 'name', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function show(CharityLocation $charityLocation)
    {
        return response()->json(
            $charityLocation->load([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'organization:id,name',
                'main_location:id,name',
            ])
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'country_id'      => ['required', 'exists:countries,id'],
            'region_id'       => ['nullable', 'exists:regions,id'],
            'city_id'         => ['nullable', 'exists:cities,id'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'main_location_id'=> ['nullable', 'exists:main_locations,id'],

            'name'                   => ['required', 'string', 'max:255'],
            'phone'                  => ['nullable', 'string', 'max:30'],
            'email'                  => ['nullable', 'email', 'max:255'],
            'contact_person_name'    => ['nullable', 'string', 'max:255'],
            'contact_person_phone'   => ['nullable', 'string', 'max:30'],
            'contact_person_email'   => ['nullable', 'email', 'max:255'],
            'address_line1'          => ['nullable', 'string', 'max:255'],
            'address_line2'          => ['nullable', 'string', 'max:255'],
            'postal_code'            => ['nullable', 'string', 'max:50'],
            'notes'                  => ['nullable', 'string'],
            'is_active'              => ['sometimes', 'boolean'],
        ]);

        $charityLocation = CharityLocation::create($validated);

        return response()->json(
            $charityLocation->load([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'organization:id,name',
                'main_location:id,name',
            ]),
            201
        );
    }

    public function update(Request $request, CharityLocation $charityLocation)
    {
        $validated = $request->validate([
            'country_id'      => ['required', 'exists:countries,id'],
            'region_id'       => ['nullable', 'exists:regions,id'],
            'city_id'         => ['nullable', 'exists:cities,id'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'main_location_id'=> ['nullable', 'exists:main_locations,id'],

            'name'                   => ['required', 'string', 'max:255'],
            'phone'                  => ['nullable', 'string', 'max:30'],
            'email'                  => ['nullable', 'email', 'max:255'],
            'contact_person_name'    => ['nullable', 'string', 'max:255'],
            'contact_person_phone'   => ['nullable', 'string', 'max:30'],
            'contact_person_email'   => ['nullable', 'email', 'max:255'],
            'address_line1'          => ['nullable', 'string', 'max:255'],
            'address_line2'          => ['nullable', 'string', 'max:255'],
            'postal_code'            => ['nullable', 'string', 'max:50'],
            'notes'                  => ['nullable', 'string'],
            'is_active'              => ['sometimes', 'boolean'],
        ]);

        $charityLocation->update($validated);

        return response()->json(
            $charityLocation->load([
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'organization:id,name',
                'main_location:id,name',
            ])
        );
    }

    public function destroy(CharityLocation $charityLocation)
    {
        $charityLocation->delete();

        return response()->json([
            'message' => 'Charity location deleted successfully',
        ]);
    }



}
