<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CommissionProfiles;
use Illuminate\Support\Facades\DB;
use App\Models\CommissionProfilesShares;
 

class CommissionProfileController extends Controller
{
     /**
     * List commission profiles (with counts & sum of percentages).
     */
    public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');
        $sortDir  = $request->query('sortDir', 'desc');
        $perPage  = (int) $request->query('per_page', 10);
        $isActive = $request->input('is_active'); // null, '1', '0'

        try{

       

        $query = CommissionProfiles::query()
            ->withCount('shares')
            ->withSum('shares', 'percentage'); // gives shares_sum_percentage

        if (!is_null($isActive) && $isActive !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->with('shares');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! in_array($sortBy, ['id', 'name', 'is_active', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );

         }catch(\Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show one commission profile with shares.
     */
    public function show(CommissionProfiles $commissionProfile)
    {
        $commissionProfile->load([
            'shares' => function ($q) {
                $q->orderBy('sort_order')->orderBy('id');
            },
            'shares.organization',
        ]);

        return response()->json($commissionProfile);
    }

    /** 
     * Create profile + its shares.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],

            'shares'                  => ['required', 'array', 'min:1'],
            'shares.*.label'          => ['required', 'string', 'max:255'],
            'shares.*.percentage'     => ['required', 'numeric', 'min:0', 'max:100'],
            'shares.*.organization_id'=> ['nullable', 'exists:organizations,id'],
        ]);


        try{

       

        if (!array_key_exists('is_active', $validated)) {
            $validated['is_active'] = true;
        }

        $sharesPayload = $validated['shares'];
        unset($validated['shares']);    

        $profile = DB::transaction(function () use ($validated, $sharesPayload) {


            $profile = CommissionProfiles::create($validated);

            foreach ($sharesPayload as $index => $shareData) {
                CommissionProfilesShares::create([
                    'commission_profile_id' => $profile->id,
                    'organization_id'       => $shareData['organization_id'] ?? null,
                    'label'                 => $shareData['label'],
                    'percentage'            => $shareData['percentage'],
                    'sort_order'            => $index + 1,
                ]);
            }

            return $profile;
        });

        $profile->load([
            'shares' => function ($q) {
                $q->orderBy('sort_order')->orderBy('id');
            },
            'shares.organization',
        ]);

        return response()->json($profile, 201);

         }catch(\Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update profile + replace all its shares.
     */
        public function update(Request $request, CommissionProfiles $commissionProfile)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],

            'shares'                  => ['required', 'array', 'min:1'],
            'shares.*.label'          => ['required', 'string', 'max:255'],
            'shares.*.percentage'     => ['required', 'numeric', 'min:0', 'max:100'],
            'shares.*.organization_id'=> ['nullable', 'exists:organizations,id'],
        ]);

        $sharesPayload = $validated['shares'];
        unset($validated['shares']);

        if (!array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $commissionProfile->is_active;
        }

        $profile = DB::transaction(function () use ($commissionProfile, $validated, $sharesPayload) {
            // update main profile
            $commissionProfile->update($validated);

            // delete old shares
            $commissionProfile->shares()->delete();

            // recreate shares
            foreach ($sharesPayload as $index => $shareData) {
                CommissionProfilesShares::create([
                    'commission_profile_id' => $commissionProfile->id,
                    'organization_id'       => $shareData['organization_id'] ?? null,
                    'label'                 => $shareData['label'],
                    'percentage'            => $shareData['percentage'],
                    'sort_order'            => $index + 1,
                ]);
            }

            return $commissionProfile;
        });

        $profile->load([
            'shares' => function ($q) {
                $q->orderBy('sort_order')->orderBy('id');
            },
            'shares.organization',
        ]);

        return response()->json($profile);
    }

    /**
     * Delete profile (shares cascade on delete).
     */
    public function destroy(CommissionProfiles $commissionProfile)
    {
        $commissionProfile->delete();

        return response()->json([
            'message' => 'Commission profile deleted successfully',
        ]);
    }
}
