<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;

class OrganizationController extends Controller
{
        /**
     * Paginated list with search/sort, including parent + primary user.
     */
    public function index(Request $request)
    {
        $search  = $request->query('search');
        $sortBy  = $request->query('sortBy', 'id');
        $sortDir = $request->query('sortDir', 'desc');
        $perPage = (int) $request->query('per_page', 10);

        $query = Organization::query()
            ->with([
                'parent:id,name',
                'primaryUser:id,name,email,organization_id',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('trade_name', 'like', "%{$search}%")
                  ->orWhere('cr_number', 'like', "%{$search}%");
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'name', 'is_active', 'created_at'])) {
            $sortBy = 'id';
        }

        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    /**
     * Lightweight list (id + name) for dropdowns (parent selector).
     */
    public function listAll()
    {
        $organizations = Organization::select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($organizations);
    }

    /**
     * Create organization + (optionally) organizer login in one request.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Organization fields
            'name'       => ['required', 'string', 'max:255'],
            'parent_id'  => ['nullable', 'exists:organizations,id'],
            'is_active'  => ['nullable', 'boolean'],

            // Optional – you can start using these later if you want
            'trade_name'    => ['nullable', 'string', 'max:255'],
            'cr_number'     => ['nullable', 'string', 'max:255'],
            'tax_number'    => ['nullable', 'string', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'email'         => ['nullable', 'email', 'max:255'],
            'website'       => ['nullable', 'string', 'max:255'],
            'country_id'    => ['nullable', 'exists:countries,id'],
            'region_id'     => ['nullable', 'exists:regions,id'],
            'city_id'       => ['nullable', 'exists:cities,id'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code'   => ['nullable', 'string', 'max:50'],
            'bank_id'       => ['nullable', 'exists:banks,id'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'iban'          => ['nullable', 'string', 'max:255'],
            'account_number'=> ['nullable', 'string', 'max:255'],
            'swift_code'    => ['nullable', 'string', 'max:255'],
            'notes'         => ['nullable', 'string'],

            // "Create login" block
            'create_user'                => ['nullable', 'boolean'],
            'user_name'                  => ['required_if:create_user,true', 'string', 'max:255'],
            'user_email'                 => ['required_if:create_user,true', 'email', 'max:255', 'unique:users,email'],
            'user_password'              => ['required_if:create_user,true', 'string', 'min:8', 'confirmed'],
            // expected field: user_password_confirmation
        ]);

         try{


        $payload = DB::transaction(function () use ($request, $validated) {
            // Build org data from validated fields
            $orgData = collect($validated)->only([
                'name',
                'trade_name',
                'cr_number',
                'tax_number',
                'phone',
                'email',
                'website',
                'country_id',
                'region_id',
                'city_id',
                'address_line1',
                'address_line2',
                'postal_code',
                'bank_id',
                'bank_account_name',
                'iban',
                'account_number',
                'swift_code',
                'is_active',
                'notes',
                'parent_id',
            ])->toArray();

            if (! array_key_exists('is_active', $orgData)) {
                $orgData['is_active'] = true;
            }

            $organization = Organization::create($orgData);

            $user = null;
            if ($request->boolean('create_user')) {
                $user = User::create([
                    'name'            => $request->input('user_name'),
                    'email'           => $request->input('user_email'),
                    'password'        => Hash::make($request->input('user_password')),
                    'organization_id' => $organization->id,
                ]);

                // If you use Spatie roles, you could attach role here later.
            }

            return [
                'organization' => $organization->load([
                    'parent:id,name',
                    'primaryUser:id,name,email,organization_id',
                ]),
                'user' => $user,
            ];
        });


        }catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating organization',
                'error'   => $e->getMessage(),
            ], 500);
        }

        return response()->json($payload, 201);
    }

    public function show(Organization $organization)
    {
        return response()->json(
            $organization->load([
                'parent:id,name',
                'primaryUser:id,name,email,organization_id',
            ])
        );
    }

    public function update(Request $request, Organization $organization)
{
    $primaryUser = $organization->primaryUser; // may be null

    $primaryUserId = $primaryUser ? $primaryUser->id : null;

    $validated = $request->validate([
        'name'      => ['required', 'string', 'max:255'],
        'parent_id' => ['nullable', 'exists:organizations,id'],
        'is_active' => ['nullable', 'boolean'],

        'trade_name'    => ['nullable', 'string', 'max:255'],
        'cr_number'     => ['nullable', 'string', 'max:255'],
        'tax_number'    => ['nullable', 'string', 'max:255'],
        'phone'         => ['nullable', 'string', 'max:30'],
        'email'         => ['nullable', 'email', 'max:255'],
        'website'       => ['nullable', 'string', 'max:255'],
        'country_id'    => ['nullable', 'exists:countries,id'],
        'region_id'     => ['nullable', 'exists:regions,id'],
        'city_id'       => ['nullable', 'exists:cities,id'],
        'address_line1' => ['nullable', 'string', 'max:255'],
        'address_line2' => ['nullable', 'string', 'max:255'],
        'postal_code'   => ['nullable', 'string', 'max:50'],
        'bank_id'       => ['nullable', 'exists:banks,id'],
        'bank_account_name' => ['nullable', 'string', 'max:255'],
        'iban'          => ['nullable', 'string', 'max:255'],
        'account_number'=> ['nullable', 'string', 'max:255'],
        'swift_code'    => ['nullable', 'string', 'max:255'],
        'notes'         => ['nullable', 'string'],

        // OPTIONAL login edits for primary user
        'user_name'  => ['nullable', 'string', 'max:255'],
        'user_email' => [
            'nullable',
            'email',
            'max:255',
            \Illuminate\Validation\Rule::unique('users', 'email')->ignore($primaryUserId),
        ],
        'user_password' => ['nullable', 'string', 'min:8', 'confirmed'],
        // expects user_password_confirmation if user_password is present
    ]);

    
$orgData = Arr::except($validated, ['user_name','user_email','user_password','user_password_confirmation']);
$organization->update($orgData);

    // If there is a primary user and some login fields were sent, update them
    if ($primaryUser && (
        $request->filled('user_name') ||
        $request->filled('user_email') ||
        $request->filled('user_password')
    )) {
        $userData = [];

        if ($request->filled('user_name')) {
            $userData['name'] = $request->input('user_name');
        }

        if ($request->filled('user_email')) {
            $userData['email'] = $request->input('user_email');
        }

        if ($request->filled('user_password')) {
            $userData['password'] = \Illuminate\Support\Facades\Hash::make(
                $request->input('user_password')
            );
        }

        if (! empty($userData)) {
            $primaryUser->update($userData);
        }
    }

    return response()->json(
        $organization->load([
            'parent:id,name',
            'primaryUser:id,name,email,organization_id',
        ])
    );
}


    public function destroy(Organization $organization)
    {
        $organization->delete();

        return response()->json([
            'message' => 'Organization deleted successfully',
        ]);
    }



}
