<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
 
     // GET /api/activities?q=&per_page=
    public function index(Request $request)
    {
        $perPage = (int) ($request->query('per_page', 50));
        $q = trim((string) $request->query('q', ''));

        $query = Activity::query()
            ->withCount('companies')
            ->orderBy('name');

        if ($q !== '') {
            $query->where('name', 'ilike', "%{$q}%");
        }

        return response()->json($query->paginate($perPage));
    }

    // POST /api/activities
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255', 'unique:activities,name'],
            'notes'     => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try{
             $activity = Activity::create($data);

        return response()->json([
            'message' => 'Activity created successfully',
            'data' => $activity,
        ], 201);
        }catch(\Exception $e){
            return response()->json([
                'message' => 'Error checking existing activity: ' . $e->getMessage(),
            ], 500);
        }

      
    }

    // GET /api/activities/{activity}
    public function show(Activity $activity)
    {
        return response()->json([
            'data' => $activity->load(['companies:id,name']),
        ]);
    }

    // PUT/PATCH /api/activities/{activity}
    public function update(Request $request, Activity $activity)
    {
        $data = $request->validate([
            'name'      => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('activities', 'name')->ignore($activity->id),
            ],
            'notes'     => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $activity->update($data);

        return response()->json([
            'message' => 'Activity updated successfully',
            'data' => $activity,
        ]);
    }

    // DELETE /api/activities/{activity}
    public function destroy(Activity $activity)
    {
        $activity->delete();

        return response()->json([
            'message' => 'Activity deleted successfully',
        ]);
    }
}
