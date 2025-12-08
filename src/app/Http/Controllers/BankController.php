<?php

namespace App\Http\Controllers;

use App\Models\Banks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankController extends Controller
{
      public function index(Request $request)
    {
        $search    = $request->query('search');
        $sortBy    = $request->query('sortBy', 'id');
        $sortDir   = $request->query('sortDir', 'desc');
        $perPage   = (int) $request->query('per_page', 10);
        $countryId = $request->query('country_id');
        $isActive  = $request->input('is_active'); // can be '1', '0', or null

        $query = Banks::query()->with('country');

        // Filter by country
        if ($countryId) {
            $query->where('country_id', $countryId);
        }

        // Filter by active flag
        if (!is_null($isActive) && $isActive !== '') {
            // front-end sends 1 or 0
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search by name / short_name / swift_code / branch_name
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_name', 'like', "%{$search}%")
                  ->orWhere('swift_code', 'like', "%{$search}%")
                  ->orWhere('branch_name', 'like', "%{$search}%");
            });
        }

        // Whitelist sortable columns
        if (! in_array($sortBy, ['id', 'name', 'country_id', 'swift_code', 'created_at'], true)) {
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
            'name'        => ['required', 'string', 'max:255',
                Rule::unique('banks')->where(function ($q) use ($request) {
                    return $q->where('country_id', $request->country_id);
                }),
            ],
            'short_name'  => ['nullable', 'string', 'max:255'],
            'country_id'  => ['nullable', 'exists:countries,id'],
            'swift_code'  => ['nullable', 'string', 'max:255'],
            'iban_example'=> ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:30'],
            'email'       => ['nullable', 'email', 'max:255'],
            'website'     => ['nullable', 'string', 'max:255'],
            'is_active'   => ['boolean'],
            'notes'       => ['nullable', 'string'],
        ]);

        // default active = true if not provided
        if (!array_key_exists('is_active', $validated)) {
            $validated['is_active'] = true;
        }

        $bank = Banks::create($validated);

        return response()->json($bank->load('country'), 201);
    }

    public function update(Request $request, Banks $bank)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255',
                Rule::unique('banks')
                    ->ignore($bank->id)
                    ->where(function ($q) use ($request) {
                        return $q->where('country_id', $request->country_id);
                    }),
            ],
            'short_name'  => ['nullable', 'string', 'max:255'],
            'country_id'  => ['nullable', 'exists:countries,id'],
            'swift_code'  => ['nullable', 'string', 'max:255'],
            'iban_example'=> ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:30'],
            'email'       => ['nullable', 'email', 'max:255'],
            'website'     => ['nullable', 'string', 'max:255'],
            'is_active'   => ['boolean'],
            'notes'       => ['nullable', 'string'],
        ]);

        // If is_active not included, keep current
        if (! array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $bank->is_active;
        }

        $bank->update($validated);

        return response()->json($bank->load('country'));
    }

    public function destroy(Banks $bank)
    {
        $bank->delete();

        return response()->json([
            'message' => 'Bank deleted successfully',
        ]);
    }
}
