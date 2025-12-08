<?php

namespace App\Http\Controllers;

use App\Models\DeviceModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceModelController extends Controller
{
    public function index(Request $request)
    {

         try{
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $sortDir  = $request->query('sortDir', 'desc');
        $perPage  = (int) $request->query('per_page', 10);
        $brandId  = $request->query('brand_id');

       
        $query = DeviceModel::query()->with('deviceBrand');

        // optional filter by brand
        if ($brandId) {
            $query->where('device_brand_id', $brandId);
        }

        // search by model name or brand name
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('brand', function ($qb) use ($search) {
                      $qb->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'name', 'device_brand_id', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        }catch(\Exception $e){
            return response()->json([
                'message' => 'An error occurred while fetching device models.',
                'error' => $e->getMessage(),
            ], 400);
        } 

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_brand_id' => ['required', 'exists:device_brands,id'],
            'name'            => [
                'required',
                'string',
                'max:255',
                Rule::unique('device_models')->where(function ($q) use ($request) {
                    return $q->where('device_brand_id', $request->device_brand_id);
                }),
            ],
        ]);

        $model = DeviceModel::create($validated);

        return response()->json($model->load('deviceBrand'), 201);
    }

    public function update(Request $request, DeviceModel $deviceModel)
    {
        $validated = $request->validate([
            'device_brand_id' => ['required', 'exists:device_brands,id'],
            'name'            => [
                'required',
                'string',
                'max:255',
                Rule::unique('device_models')
                    ->ignore($deviceModel->id)
                    ->where(function ($q) use ($request) {
                        return $q->where('device_brand_id', $request->device_brand_id);
                    }),
            ],
        ]);

        $deviceModel->update($validated);

        return response()->json($deviceModel->load('deviceBrand'));
    }

    public function destroy(DeviceModel $deviceModel)
    {
        $deviceModel->delete();

        return response()->json([
            'message' => 'Device model deleted successfully',
        ]);
    }
}
