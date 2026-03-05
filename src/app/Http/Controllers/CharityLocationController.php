<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CharityLocation;
use Illuminate\Support\Facades\DB;
use App\Models\MainLocation;

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
                'district:id,name',          // 👈 NEW
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
                'district:id,name',          // 👈 NEW
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
            'district_id'     => ['nullable', 'exists:districts,id'],  // 👈 NEW
            'city_id'         => ['nullable', 'exists:cities,id'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'main_location_id' => ['nullable', 'exists:main_locations,id'],

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
                'district:id,name',          // 👈 NEW
                'city:id,name',
                'organization:id,name',
                'main_location:id,name',
            ]),
            201
        );
    }



    public function storeBulk(Request $request)
    {
        $validated = $request->validate([
            'main_location_id' => ['required', 'exists:main_locations,id'],
            'locations' => ['required', 'array', 'min:1'],
    
            'locations.*.name' => ['required', 'string', 'max:255'],
    
            // optional fields (per location)
            'locations.*.phone' => ['nullable', 'string', 'max:50'],
            'locations.*.email' => ['nullable', 'email', 'max:255'],
            'locations.*.contact_person_name' => ['nullable', 'string', 'max:255'],
            'locations.*.contact_person_phone' => ['nullable', 'string', 'max:50'],
            'locations.*.contact_person_email' => ['nullable', 'email', 'max:255'],
            'locations.*.address_line1' => ['nullable', 'string', 'max:255'],
            'locations.*.address_line2' => ['nullable', 'string', 'max:255'],
            'locations.*.postal_code' => ['nullable', 'string', 'max:50'],
            'locations.*.notes' => ['nullable', 'string'],
            'locations.*.is_active' => ['nullable', 'boolean'],
        ]);
    
        $main = MainLocation::query()
            ->select('id', 'country_id', 'region_id', 'district_id', 'city_id', 'organization_id')
            ->findOrFail((int) $validated['main_location_id']);
    
        $snapshot = [
            'country_id'      => $main->country_id,
            'region_id'       => $main->region_id,
            'district_id'     => $main->district_id,
            'city_id'         => $main->city_id,
            'organization_id' => $main->organization_id,
        ];
    
        $created = DB::transaction(function () use ($validated, $snapshot) {
            $rows = [];
    
            foreach ($validated['locations'] as $loc) {
                $rows[] = CharityLocation::create(array_merge($snapshot, [
                    'main_location_id' => (int) $validated['main_location_id'],
                    'name' => $loc['name'],
    
                    'phone' => $loc['phone'] ?? null,
                    'email' => $loc['email'] ?? null,
                    'contact_person_name' => $loc['contact_person_name'] ?? null,
                    'contact_person_phone' => $loc['contact_person_phone'] ?? null,
                    'contact_person_email' => $loc['contact_person_email'] ?? null,
                    'address_line1' => $loc['address_line1'] ?? null,
                    'address_line2' => $loc['address_line2'] ?? null,
                    'postal_code' => $loc['postal_code'] ?? null,
                    'notes' => $loc['notes'] ?? null,
                    'is_active' => isset($loc['is_active']) ? (bool) $loc['is_active'] : true,
                ]));
            }
    
            return $rows;
        });
    
        return response()->json([
            'message' => 'Charity locations created successfully.',
            'data' => $created,
        ], 201);
    }
    public function update(Request $request, CharityLocation $charityLocation)
    {
        $validated = $request->validate([
            'country_id'      => ['required', 'exists:countries,id'],
            'region_id'       => ['nullable', 'exists:regions,id'],
            'district_id'     => ['nullable', 'exists:districts,id'],  // 👈 NEW
            'city_id'         => ['nullable', 'exists:cities,id'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'main_location_id' => ['nullable', 'exists:main_locations,id'],

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
                'district:id,name',          // 👈 NEW
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
