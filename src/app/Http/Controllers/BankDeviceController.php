<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use App\Models\MainLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BankDeviceController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $sortBy = (string) $request->query('sortBy', 'id');
        $sortDir = strtolower((string) $request->query('sortDir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 200));

        $status = $request->query('status');
        $bankId = $request->query('bank_id');
        $companyId = $request->query('company_id') ?? $request->query('companies_id');
        $mainLocationId = $request->query('main_location_id');
        $charityLocationId = $request->query('charity_location_id');
        $commissionProfileId = $request->query('commission_profile_id');

        $query = $this->bankDevicesQuery();

        if ($status) {
            $query->where('status', $status);
        }

        if ($bankId) {
            $query->where('bank_id', (int) $bankId);
        }

        if ($companyId) {
            $query->where('companies_id', (int) $companyId);
        }

        if ($mainLocationId) {
            $query->where('main_location_id', (int) $mainLocationId);
        }

        if ($charityLocationId) {
            $query->where('charity_location_id', (int) $charityLocationId);
        }

        if ($commissionProfileId) {
            $query->where('commission_profile_id', (int) $commissionProfileId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('device_code', 'like', "%{$search}%")
                    ->orWhere('bank_username', 'like', "%{$search}%")
                    ->orWhere('model_number', 'like', "%{$search}%")
                    ->orWhereHas('mainLocation', fn ($mainLocationQuery) => $mainLocationQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('charityLocation', fn ($charityLocationQuery) => $charityLocationQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('bank', fn ($bankQuery) => $bankQuery->where('name', 'like', "%{$search}%"));
            });
        }

        if (!in_array($sortBy, ['id', 'device_code', 'status', 'installed_at', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $paginator = $query
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (Devices $device) => $this->serializeBankDevice($device)
            )
        );

        return response()->json($paginator);
    }

    public function show(Devices $device)
    {
        $device = $this->loadBankDeviceOrFail($device);

        return response()->json($this->serializeBankDevice($device, true));
    }

    public function store(Request $request)
    {
        $validated = $this->validateBankDevice($request);
        $this->hydrateInheritedLocationData($validated);
        $validated['device_code'] = $this->generateUniqueDeviceCode();

        $device = Devices::create($validated);

        return response()->json([
            'success' => true,
            'device' => $this->serializeBankDevice(
                $this->loadBankRelations($device->fresh()),
            ),
        ], 201);
    }

    public function update(Request $request, Devices $device)
    {
        $device = $this->loadBankDeviceOrFail($device);
        $validated = $this->validateBankDevice($request, $device);
        $this->hydrateInheritedLocationData($validated);
        $validated['device_code'] = $device->device_code ?: $this->generateUniqueDeviceCode();

        $device->update($validated);

        return response()->json([
            'success' => true,
            'device' => $this->serializeBankDevice(
                $this->loadBankRelations($device->fresh()),
            ),
        ]);
    }

    public function destroy(Devices $device)
    {
        $device = $this->loadBankDeviceOrFail($device);
        $device->delete();

        return response()->json([
            'message' => 'Bank device deleted successfully',
        ]);
    }

    public function resolve(Request $request)
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string', 'max:255'],
        ]);

        $deviceCode = strtoupper(trim($validated['device_code']));

        $device = $this->bankDevicesQuery()
            ->where('device_code', $deviceCode)
            ->where('status', 'active')
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Bank device not found or inactive.',
            ], 404);
        }

        if (blank($device->bank_username) || blank($device->bank_password)) {
            return response()->json([
                'success' => false,
                'message' => 'Bank device credentials are incomplete.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'device_id' => $device->id,
                'device_code' => $device->device_code,
                'username' => $device->bank_username,
                'password' => $device->bank_password,
                'bank_id' => $device->bank_id,
                'status' => $device->status,
            ],
        ]);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'max:255'],
        ]);

        $deviceCode = strtoupper(trim($validated['device_code']));
        $username = trim($validated['username']);

        $device = $this->bankDevicesQuery()
            ->where('device_code', $deviceCode)
            ->where('bank_username', $username)
            ->where('status', 'active')
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Bank device not found or inactive.',
            ], 404);
        }

        if (!hash_equals((string) $device->bank_password, (string) $validated['current_password'])) {
            return response()->json([
                'success' => false,
                'message' => 'Current Bank Nizwa password does not match the stored device password.',
            ], 409);
        }

        $device->bank_password = $validated['new_password'];
        $device->save();

        return response()->json([
            'success' => true,
            'message' => 'Bank Nizwa password updated successfully.',
        ]);
    }

    private function validateBankDevice(Request $request, ?Devices $device = null): array
    {
        return $request->validate([
            'device_brand_id' => ['required', 'exists:device_brands,id'],
            'device_model_id' => ['required', 'exists:device_models,id'],
            'bank_id' => ['required', 'exists:banks,id'],
            'commission_profile_id' => ['required', 'exists:commission_profiles,id'],
            'main_location_id' => ['required', 'exists:main_locations,id'],
            'charity_location_id' => [
                'required',
                Rule::exists('charity_locations', 'id')->where(function ($query) use ($request) {
                    $query->where('main_location_id', $request->input('main_location_id'));
                }),
            ],
            'model_number' => ['nullable', 'string', 'max:255'],
            'bank_username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('devices', 'bank_username')->ignore($device?->id),
            ],
            'bank_password' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'disabled', 'maintenance'])],
            'installed_at' => ['nullable', 'date'],
        ]);
    }

    private function hydrateInheritedLocationData(array &$validated): void
    {
        $mainLocation = MainLocation::select(
            'id',
            'company_id',
            'country_id',
            'region_id',
            'district_id',
            'city_id'
        )->find($validated['main_location_id']);

        if (!$mainLocation) {
            throw ValidationException::withMessages([
                'main_location_id' => 'Invalid main location.',
            ]);
        }

        if (empty($mainLocation->company_id)) {
            throw ValidationException::withMessages([
                'main_location_id' => 'Selected Main Location has no company_id. Please set company in Main Location first.',
            ]);
        }

        $validated['companies_id'] = $mainLocation->company_id;
        $validated['country_id'] = $mainLocation->country_id;
        $validated['region_id'] = $mainLocation->region_id;
        $validated['district_id'] = $mainLocation->district_id;
        $validated['city_id'] = $mainLocation->city_id;
        $validated['kiosk_id'] = null;
        $validated['terminal_id'] = null;
    }

    private function bankDevicesQuery()
    {
        return Devices::query()
            ->with($this->bankRelations())
            ->where(function ($query) {
                $query->whereNotNull('device_code')
                    ->where('device_code', '<>', '')
                    ->orWhere(function ($credentialQuery) {
                        $credentialQuery->whereNotNull('bank_username')
                            ->where('bank_username', '<>', '');
                    });
            });
    }

    private function bankRelations(): array
    {
        return [
            'deviceBrand',
            'deviceModel',
            'bank',
            'country',
            'region',
            'district',
            'city',
            'charityLocation',
            'commissionProfile',
            'company:id,name',
            'mainLocation:id,name,company_id',
            'mainLocation.company:id,name',
        ];
    }

    private function loadBankDeviceOrFail(Devices $device): Devices
    {
        $device = $this->loadBankRelations($device);

        if (blank($device->device_code) && blank($device->bank_username)) {
            abort(404, 'Bank device not found');
        }

        return $device;
    }

    private function loadBankRelations(Devices $device): Devices
    {
        return $device->load($this->bankRelations());
    }

    private function generateUniqueDeviceCode(): string
    {
        do {
            $deviceCode = 'BNZ-' . Str::upper(Str::random(8));
        } while (Devices::where('device_code', $deviceCode)->exists());

        return $deviceCode;
    }

    private function serializeBankDevice(Devices $device, bool $includePassword = false): array
    {
        return [
            'id' => $device->id,
            'device_code' => $device->device_code,
            'companies_id' => $device->companies_id,
            'company' => $device->company,
            'main_location_id' => $device->main_location_id,
            'mainLocation' => $device->mainLocation,
            'device_brand_id' => $device->device_brand_id,
            'device_model_id' => $device->device_model_id,
            'bank_id' => $device->bank_id,
            'bank' => $device->bank,
            'commission_profile_id' => $device->commission_profile_id,
            'commissionProfile' => $device->commissionProfile,
            'charity_location_id' => $device->charity_location_id,
            'charityLocation' => $device->charityLocation,
            'model_number' => $device->model_number,
            'bank_username' => $device->bank_username,
            'bank_password' => $includePassword ? $device->bank_password : null,
            'has_bank_password' => filled($device->bank_password),
            'status' => $device->status,
            'installed_at' => optional($device->installed_at)->toDateString() ?? $device->installed_at,
            'created_at' => $device->created_at?->toISOString(),
            'updated_at' => $device->updated_at?->toISOString(),
        ];
    }
}
