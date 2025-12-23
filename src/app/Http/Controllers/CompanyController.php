<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
 
class CompanyController extends Controller
{
    // GET /api/companies?q=&activity_id=&per_page=
    public function index(Request $request)
    {
        $perPage = (int) ($request->query('per_page', 15));
        $q = trim((string) $request->query('q', ''));
        $activityId = $request->query('activity_id');

        $query = Company::query()
            ->with(['activities:id,name'])
            ->withCount('activities')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%")
                    ->orWhere('phone_number', 'ilike', "%{$q}%");
            });
        }

        if ($activityId) {
            $query->whereHas('activities', fn($a) => $a->where('activities.id', $activityId));
        }

        return response()->json($query->paginate($perPage));
    }

    public function listAll(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        return response()->json(
            Company::query()
                ->select('id', 'name')
                ->when($q !== '', fn($x) => $x->where('name', 'like', "%{$q}%"))
                ->orderBy('name')
                ->limit(50)
                ->get()
        );
    }

    // POST /api/companies
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['nullable', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'notes'        => ['nullable', 'string'],
            'is_active'    => ['nullable', 'boolean'],

            // ✅ activity sync on create
            'activity_ids'   => ['nullable', 'array'],
            'activity_ids.*' => ['integer', 'distinct', 'exists:activities,id'],
        ]);



        $company = Company::create($data);

        // ✅ Sync pivot
        $company->activities()->sync($data['activity_ids'] ?? []);

        return response()->json([
            'message' => 'Company created successfully',
            'data' => $company->load(['activities:id,name']),
        ], 201);
    }

    // GET /api/companies/{company}
    public function show(Company $company)
    {
        return response()->json([
            'data' => $company->load(['activities:id,name']),
        ]);
    }

    // PUT/PATCH /api/companies/{company}
    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name'         => ['sometimes', 'required', 'string', 'max:255'],
            'email'        => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes'        => ['sometimes', 'nullable', 'string'],
            'is_active'    => ['sometimes', 'nullable', 'boolean'],

            // If provided, update pivot
            'activity_ids'   => ['sometimes', 'nullable', 'array'],
            'activity_ids.*' => ['integer', 'distinct', 'exists:activities,id'],
        ]);

        $company->update($data);

        // ✅ Only sync if activity_ids exists in request payload
        if ($request->has('activity_ids')) {
            $company->activities()->sync($data['activity_ids'] ?? []);
        }

        return response()->json([
            'message' => 'Company updated successfully',
            'data' => $company->load(['activities:id,name']),
        ]);
    }

    // DELETE /api/companies/{company}
    public function destroy(Company $company)
    {
        $company->delete();

        return response()->json([
            'message' => 'Company deleted successfully',
        ]);
    }
}
