<?php

namespace App\Http\Controllers;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegionController extends Controller
{
    public function index(Request $request)
    {
        $search    = $request->query('search');
        $sortBy    = $request->query('sortBy', 'id');
        $sortDir   = $request->query('sortDir', 'desc');
        $perPage   = (int) $request->query('per_page', 10);
        $countryId = $request->query('country_id'); // optional filter

        $query = Region::query()->with('country');

        // optional filter by country
        if ($countryId) {
            $query->where('country_id', $countryId);
        }

        // search by name, type, code
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'name', 'code', 'created_at'])) {
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
            'country_id' => ['required', 'exists:countries,id'],
            'name'       => [
                'required',
                'string',
                'max:255',
                // unique per country
                Rule::unique('regions', 'name')->where('country_id', $request->country_id),
            ],
            'type'       => ['nullable', 'string', 'max:100'],
            'code'       => ['nullable', 'string', 'max:50'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        if (! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }

        $region = Region::create($data);

        return response()->json($region, 201);
    }

    public function show(Region $region)
    {
        $region->load('country');

        return response()->json($region);
    }

    public function update(Request $request, Region $region)
    {
        $data = $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'name'       => [
                'required',
                'string',
                'max:255',
                Rule::unique('regions', 'name')
                    ->where('country_id', $request->country_id)
                    ->ignore($region->id),
            ],
            'type'       => ['nullable', 'string', 'max:100'],
            'code'       => ['nullable', 'string', 'max:50'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $region->update($data);

        return response()->json($region);
    }

    public function destroy(Region $region)
    {
        $region->delete();

        return response()->json(['message' => 'Region deleted successfully']);
    }
}