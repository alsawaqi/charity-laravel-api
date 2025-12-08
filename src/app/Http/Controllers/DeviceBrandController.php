<?php

namespace App\Http\Controllers;

use App\Models\DeviceBrand;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class DeviceBrandController extends Controller
{
    public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $sortDir  = $request->query('sortDir', 'desc');
        $perPage  = (int) $request->query('per_page', 10);

        $query = DeviceBrand::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (! in_array($sortBy, ['id', 'name', 'slug', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255', 'unique:device_brands,name'],
            'slug'  => ['nullable', 'string', 'max:255', 'unique:device_brands,slug'],
            'notes' => ['nullable', 'string'],
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $brand = DeviceBrand::create($validated);

        return response()->json($brand, 201);
    }

    public function update(Request $request, DeviceBrand $deviceBrand)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255', 'unique:device_brands,name,' . $deviceBrand->id],
            'slug'  => ['nullable', 'string', 'max:255', 'unique:device_brands,slug,' . $deviceBrand->id],
            'notes' => ['nullable', 'string'],
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $deviceBrand->update($validated);

        return response()->json($deviceBrand);
    }

    public function destroy(DeviceBrand $deviceBrand)
    {
        $deviceBrand->delete();

        return response()->json([
            'message' => 'Device brand deleted successfully',
        ]);
    }
}
