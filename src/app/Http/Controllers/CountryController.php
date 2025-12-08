<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CountryController extends Controller
{

    public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $sortDir  = $request->query('sortDir', 'desc');
        $perPage  = (int) $request->query('per_page', 10);

        $query = Country::query();

        // Search by name or ISO code
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('iso_code', 'like', "%{$search}%");
            });
        }

        // Whitelist sortable columns
        if (! in_array($sortBy, ['id', 'name', 'iso_code', 'created_at'])) {
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
            'name'       => ['required', 'string', 'max:255'],
            'iso_code'   => ['required', 'string', 'size:2', 'unique:countries,iso_code'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        // default is_active to true if not provided
        if (! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }

        $country = Country::create($data);

        return response()->json($country, 201);
    }

    public function show(Country $country)
    {
        return response()->json($country);
    }

    public function update(Request $request, Country $country)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'iso_code'   => [
                'required',
                'string',
                'size:2',
                Rule::unique('countries', 'iso_code')->ignore($country->id),
            ],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $country->update($data);

        return response()->json($country);
    }

    public function destroy(Country $country)
    {
        $country->delete();

        return response()->json([
            'message' => 'Country deleted successfully',
        ]);
    }
}
