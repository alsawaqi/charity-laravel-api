<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $search  = trim((string) $request->query('search', ''));
        $sortBy  = $request->query('sortBy', 'id');
        $sortDir = $request->query('sortDir', 'desc');
        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min($perPage, 200));

        $query = Organization::query()
            ->with([
                'parent:id,name',
                'primaryUser:id,name,email,organization_id',
                'country:id,name',
                'region:id,name',
                'city:id,name',
                'bank:id,name',
                'latestLoginLog.user:id,name,email',
                'currentLoginLog.user:id,name,email',
            ])
            ->withCount([
                'children',
                'users',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('trade_name', 'like', "%{$search}%")
                    ->orWhere('cr_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('parent', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('primaryUser', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->integer('parent_id'));
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', $request->integer('country_id'));
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->integer('city_id'));
        }

        if ($request->filled('bank_id')) {
            $query->where('bank_id', $request->integer('bank_id'));
        }

        $hierarchy = $request->query('hierarchy');
        if ($hierarchy === 'parents') {
            $query->has('children');
        } elseif ($hierarchy === 'leaves') {
            $query->doesntHave('children');
        }

        $primaryUserState = $request->query('primary_user_state');
        if ($primaryUserState === 'has') {
            $query->has('primaryUser');
        } elseif ($primaryUserState === 'missing') {
            $query->doesntHave('primaryUser');
        }

        if (!in_array($sortBy, ['id', 'name', 'is_active', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (Organization $organization) => $this->attachOrganizerLogin($organization));

        return response()->json($paginator);
    }

    public function listAll()
    {
        $organizations = Organization::select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($organizations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'parent_id'  => ['nullable', 'exists:organizations,id'],
            'is_active'  => ['nullable', 'boolean'],
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
            'create_user'                => ['nullable', 'boolean'],
            'user_name'                  => ['required_if:create_user,true', 'string', 'max:255'],
            'user_email'                 => ['required_if:create_user,true', 'email', 'max:255', 'unique:users,email'],
            'user_password'              => ['required_if:create_user,true', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $payload = DB::transaction(function () use ($request, $validated) {
                $orgData = collect($validated)->only([
                    'name', 'trade_name', 'cr_number', 'tax_number', 'phone', 'email', 'website',
                    'country_id', 'region_id', 'city_id', 'address_line1', 'address_line2', 'postal_code',
                    'bank_id', 'bank_account_name', 'iban', 'account_number', 'swift_code', 'is_active', 'notes', 'parent_id',
                ])->toArray();

                if (!array_key_exists('is_active', $orgData)) {
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
                }

                return [
                    'organization' => $organization->load([
                        'parent:id,name',
                        'primaryUser:id,name,email,organization_id',
                        'country:id,name',
                        'region:id,name',
                        'city:id,name',
                        'bank:id,name',
                    ]),
                    'user' => $user,
                ];
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating organization',
                'error'   => $e->getMessage(),
            ], 500);
        }

        return response()->json($payload, 201);
    }

    public function show(Organization $organization)
    {
        $organization->load([
            'parent:id,name',
            'primaryUser:id,name,email,organization_id',
            'country:id,name',
            'region:id,name',
            'city:id,name',
            'bank:id,name',
            'latestLoginLog.user:id,name,email',
            'currentLoginLog.user:id,name,email',
        ]);

        return response()->json($this->attachOrganizerLogin($organization, true));
    }

    public function update(Request $request, Organization $organization)
    {
        $primaryUser = $organization->primaryUser;
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
            'edit_login'    => ['nullable', 'boolean'],
            'user_name'     => ['nullable', 'string', 'max:255'],
            'user_email'    => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($primaryUserId),
            ],
            'user_password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if ((int) $organization->id === (int) ($validated['parent_id'] ?? 0)) {
            return response()->json([
                'message' => 'An organization cannot be its own parent.',
            ], 422);
        }

        if ($request->boolean('edit_login') && !$primaryUser) {
            if (!$request->filled('user_name') || !$request->filled('user_email') || !$request->filled('user_password')) {
                return response()->json([
                    'message' => 'Name, email, and password are required to create a login for this organization.',
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($request, $validated, $organization, $primaryUser) {
                $orgData = Arr::except($validated, [
                    'edit_login',
                    'user_name',
                    'user_email',
                    'user_password',
                    'user_password_confirmation',
                ]);

                $organization->update($orgData);

                if ($request->boolean('edit_login')) {
                    if ($primaryUser) {
                        $userData = [];

                        if ($request->filled('user_name')) {
                            $userData['name'] = $request->input('user_name');
                        }
                        if ($request->filled('user_email')) {
                            $userData['email'] = $request->input('user_email');
                        }
                        if ($request->filled('user_password')) {
                            $userData['password'] = Hash::make($request->input('user_password'));
                        }

                        if (!empty($userData)) {
                            $primaryUser->update($userData);
                        }
                    } else {
                        User::create([
                            'name' => $request->input('user_name'),
                            'email' => $request->input('user_email'),
                            'password' => Hash::make($request->input('user_password')),
                            'organization_id' => $organization->id,
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update organization.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $freshOrganization = $organization->fresh()->load([
            'parent:id,name',
            'primaryUser:id,name,email,organization_id',
            'country:id,name',
            'region:id,name',
            'city:id,name',
            'bank:id,name',
            'latestLoginLog.user:id,name,email',
            'currentLoginLog.user:id,name,email',
        ]);

        return response()->json($this->attachOrganizerLogin($freshOrganization, true));
    }

    public function destroy(Organization $organization)
    {
        $organization->delete();

        return response()->json([
            'message' => 'Organization deleted successfully',
        ]);
    }

    private function attachOrganizerLogin(Organization $organization, bool $includeHistory = false): Organization
    {
        $current = $organization->currentLoginLog;
        $latest = $organization->latestLoginLog;
        $summaryLog = $current ?: $latest;

        $organization->setAttribute('organizer_login', [
            'status' => $current ? 'online' : 'offline',
            'is_online' => (bool) $current,
            'latest_login_at' => $this->formatLoginDate($summaryLog?->login_at),
            'latest_logout_at' => $this->formatLoginDate($summaryLog?->logout_at),
            'user' => $summaryLog?->user ? [
                'id' => $summaryLog->user->id,
                'name' => $summaryLog->user->name,
                'email' => $summaryLog->user->email,
            ] : null,
        ]);

        if ($includeHistory) {
            $history = $organization->loginLogs()
                ->with('user:id,name,email')
                ->latest('login_at')
                ->limit(25)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'user_id' => $log->user_id,
                    'organization_id' => $log->organization_id,
                    'session_id' => $log->session_id,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'login_at' => $this->formatLoginDate($log->login_at),
                    'logout_at' => $this->formatLoginDate($log->logout_at),
                    'status' => $log->logout_at ? 'logged_out' : 'online',
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ] : null,
                ]);

            $organization->setAttribute('login_history', $history);
        }

        $organization->unsetRelation('latestLoginLog');
        $organization->unsetRelation('currentLoginLog');

        return $organization;
    }

    private function formatLoginDate($value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value)
            ->timezone(config('app.timezone', 'Asia/Muscat'))
            ->toAtomString();
    }
}
