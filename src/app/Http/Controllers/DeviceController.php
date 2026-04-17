<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use App\Models\MainLocation;
use Illuminate\Http\Request;
use App\Models\CharityLocation;
use App\Models\DeviceModel;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Services\ScalefusionService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Database\Eloquent\Builder;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $search  = trim((string) $request->query('search', ''));
        $sortBy  = (string) $request->query('sortBy', 'id');
        $sortDir = strtolower((string) $request->query('sortDir', 'asc')) === 'desc' ? 'desc' : 'asc';
    
        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));
    
        // DB filters
        $status          = $request->query('status'); // active/disabled/maintenance
        $countryId       = $request->query('country_id');
        $regionId        = $request->query('region_id');
        $districtId      = $request->query('district_id');
        $cityId          = $request->query('city_id');
        $companyId       = $request->query('company_id');         // maps to devices.companies_id
        $mainLocationId  = $request->query('main_location_id');
        $charityLocationId = $request->query('charity_location_id');
        $bankId          = $request->query('bank_id');
        $brandId         = $request->query('device_brand_id');
        $modelId         = $request->query('device_model_id');
    
        $missingKiosk    = filter_var($request->query('missing_kiosk', false), FILTER_VALIDATE_BOOL);
        $missingTerminal = filter_var($request->query('missing_terminal', false), FILTER_VALIDATE_BOOL);
    
        $installedFrom   = $request->query('installed_from'); // YYYY-MM-DD
        $installedTo     = $request->query('installed_to');   // YYYY-MM-DD
    
        // Donations filter
        $donationsDays   = (int) $request->query('donations_days', 0); // e.g. 7
        $hasDonationsRaw = $request->query('has_donations', null);     // 1/0/true/false
    
        // Scalefusion quick filters
        $sfConnection = strtolower((string) $request->query('sf_connection', '')); // online/offline
        $sfLocked     = strtolower((string) $request->query('sf_locked', ''));     // locked/unlocked
        $sfCharging   = filter_var($request->query('sf_charging', false), FILTER_VALIDATE_BOOL);
    
        $withSf = filter_var($request->query('with_scalefusion', false), FILTER_VALIDATE_BOOL);

        $commissionProfileId = $request->query('commission_profile_id');

        // compatibility (your Nuxt currently sends companies_id)
        $companyId = $request->query('company_id') ?? $request->query('companies_id');

        // presence filters: all | has | missing
        $tokenPresence  = strtolower((string) $request->query('token_presence', ''));
        $kioskPresence  = strtolower((string) $request->query('kiosk_presence', ''));
    
        try {
            $query = Devices::query()
                ->with([
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
                ]);
    
            // ---- DB filters ----
            if ($status) $query->where('status', $status);
            if ($countryId) $query->where('country_id', (int) $countryId);
            if ($regionId) $query->where('region_id', (int) $regionId);
            if ($districtId) $query->where('district_id', (int) $districtId);
            if ($cityId) $query->where('city_id', (int) $cityId);
    
            if ($companyId) $query->where('companies_id', (int) $companyId);
            if ($mainLocationId) $query->where('main_location_id', (int) $mainLocationId);
            if ($charityLocationId) $query->where('charity_location_id', (int) $charityLocationId);
    
            if ($bankId) $query->where('bank_id', (int) $bankId);
            if ($brandId) $query->where('device_brand_id', (int) $brandId);
            if ($modelId) $query->where('device_model_id', (int) $modelId);
    
            if ($missingKiosk) {
                $query->where(function ($q) {
                    $q->whereNull('kiosk_id')->orWhere('kiosk_id', '');
                });
            }
    
            if ($missingTerminal) {
                $query->where(function ($q) {
                    $q->whereNull('terminal_id')->orWhere('terminal_id', '');
                });
            }
    
            if ($installedFrom) {
                $query->whereDate('installed_at', '>=', $installedFrom);
            }
            if ($installedTo) {
                $query->whereDate('installed_at', '<=', $installedTo);
            }


            if ($commissionProfileId) {
                $query->where('commission_profile_id', (int) $commissionProfileId);
            }
            
            if ($tokenPresence === 'missing') {
                $query->where(function ($q) {
                    $q->whereNull('login_generated_token')->orWhere('login_generated_token', '');
                });
            } elseif ($tokenPresence === 'has') {
                $query->whereNotNull('login_generated_token')->where('login_generated_token', '<>', '');
            }
            
            if ($kioskPresence === 'missing') {
                $query->where(function ($q) {
                    $q->whereNull('kiosk_id')->orWhere('kiosk_id', '');
                });
            } elseif ($kioskPresence === 'has') {
                $query->whereNotNull('kiosk_id')->where('kiosk_id', '<>', '');
            }
    
            // ---- Donations in last X days ----
            if ($donationsDays > 0 && $hasDonationsRaw !== null && $hasDonationsRaw !== '') {
                $hasDonations = filter_var($hasDonationsRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    
                // support 1/0
                if ($hasDonations === null) {
                    $hasDonations = ((string) $hasDonationsRaw) === '1';
                }
    
                $cutoff = now()->subDays($donationsDays);
    
                if ($hasDonations) {
                    $query->whereExists(function ($sub) use ($cutoff) {
                        $sub->selectRaw('1')
                            ->from('charity_transactions')
                            ->whereColumn('charity_transactions.device_id', 'devices.id')
                            ->where('charity_transactions.created_at', '>=', $cutoff);
                    });
                } else {
                    $query->whereNotExists(function ($sub) use ($cutoff) {
                        $sub->selectRaw('1')
                            ->from('charity_transactions')
                            ->whereColumn('charity_transactions.device_id', 'devices.id')
                            ->where('charity_transactions.created_at', '>=', $cutoff);
                    });
                }
            }
    
            // ---- Search (DB fields) ----
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('kiosk_id', 'like', "%{$search}%")
                      ->orWhere('terminal_id', 'like', "%{$search}%")
                      ->orWhere('model_number', 'like', "%{$search}%")
                      ->orWhere('login_generated_token', 'like', "%{$search}%");
                });
            }
    
            // ---- Scalefusion filters (server-side, correct across pagination) ----
            $needsSfFiltering =
                in_array($sfConnection, ['online', 'offline'], true) ||
                in_array($sfLocked, ['locked', 'unlocked'], true) ||
                $sfCharging;
    
            if ($needsSfFiltering) {
                $kioskIds = (clone $query)
                    ->whereNotNull('kiosk_id')
                    ->where('kiosk_id', '<>', '')
                    ->pluck('kiosk_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
    
                $sfMap = app(\App\Services\ScalefusionService::class)->findDevicesByIds($kioskIds);
    
                $sfKnownIds = array_keys($sfMap);
    
                $onlineIds = [];
                $lockedIds = [];
                $chargingIds = [];
    
                foreach ($sfMap as $kid => $sf) {
                    $conn = strtolower((string) ($sf['connection_status'] ?? ''));
                    if ($conn === 'online') $onlineIds[] = $kid;
    
                    $isLocked = (bool) ($sf['locked'] ?? false);
                    $ds = strtolower((string) ($sf['device_status'] ?? ''));
                    if ($isLocked || str_contains($ds, 'lock')) $lockedIds[] = $kid;
    
                    if (!empty($sf['battery_charging'])) $chargingIds[] = $kid;
                }
    
                if ($sfConnection === 'online') {
                    $query->whereIn('kiosk_id', !empty($onlineIds) ? $onlineIds : ['__none__']);
                } elseif ($sfConnection === 'offline') {
                    // offline = NOT online, including unknown / no match
                    if (!empty($onlineIds)) {
                        $query->where(function ($q) use ($onlineIds) {
                            $q->whereNull('kiosk_id')
                              ->orWhere('kiosk_id', '')
                              ->orWhereNotIn('kiosk_id', $onlineIds);
                        });
                    } // if we have zero onlineIds, leave as-is (treat all as offline/unknown)
                }
    
                if ($sfLocked === 'locked') {
                    $query->whereIn('kiosk_id', !empty($lockedIds) ? $lockedIds : ['__none__']);
                } elseif ($sfLocked === 'unlocked') {
                    // unlocked = known ids that are NOT locked (excludes unknown)
                    $unlocked = array_values(array_diff($sfKnownIds, $lockedIds));
                    $query->whereIn('kiosk_id', !empty($unlocked) ? $unlocked : ['__none__']);
                }
    
                if ($sfCharging) {
                    $query->whereIn('kiosk_id', !empty($chargingIds) ? $chargingIds : ['__none__']);
                }
            }
    
            // sort validation
            if (!in_array($sortBy, ['id', 'kiosk_id', 'installed_at', 'status', 'created_at'], true)) {
                $sortBy = 'id';
            }
    
            $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    
            // attach scalefusion data for the current page
            if ($withSf) {
                $ids = $paginator->getCollection()
                    ->pluck('kiosk_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
    
                $sfMap = app(\App\Services\ScalefusionService::class)->findDevicesByIds($ids);
    
                $paginator->getCollection()->transform(function ($device) use ($sfMap) {
                    $key = (string) $device->kiosk_id;
                    $device->setAttribute('scalefusion', $sfMap[$key] ?? null);
                    return $device;
                });
            }
    
            return response()->json($paginator);
    
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid query parameters: ' . $e->getMessage()], 400);
        }
    }

    public function show(Devices $device)
    {
        return response()->json(
            $device->load([
                'deviceBrand',
                'deviceModel',
                'bank',
                'country',
                'district',
                'region',
                'city',
                'charityLocation',
                'commissionProfile',
                'company:id,name',                 // ✅ NEW
                'mainLocation:id,name,company_id', // ✅ NEW
                'mainLocation.company:id,name',
            ])
        );
    }

    public function editorShow(Devices $device)
    {
        $device->load([
            'deviceBrand',
            'deviceModel',
            'bank',
            'country',
            'district',
            'region',
            'city',
            'charityLocation',
            'commissionProfile',
            'company:id,name',
            'mainLocation:id,name,company_id',
            'mainLocation.company:id,name',
        ]);

        $payload = $device->toArray();

        if (blank($payload['terminal_id'] ?? null)) {
            $payload['terminal_id'] = DB::table('charity_transactions')
                ->where('device_id', $device->id)
                ->whereNotNull('terminal_id')
                ->where('terminal_id', '<>', '')
                ->orderByDesc('id')
                ->value('terminal_id');
        }

        $payload['available_device_models'] = DeviceModel::query()
            ->select('id', 'name', 'device_brand_id')
            ->when(
                $device->device_brand_id,
                fn ($query) => $query->where('device_brand_id', $device->device_brand_id)
            )
            ->orderBy('name')
            ->get()
            ->toArray();

        $payload['available_charity_locations'] = CharityLocation::query()
            ->select('id', 'name', 'main_location_id', 'organization_id')
            ->when(
                $device->main_location_id,
                fn ($query) => $query->where('main_location_id', $device->main_location_id)
            )
            ->orderBy('name')
            ->get()
            ->toArray();

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_brand_id'       => ['required', 'exists:device_brands,id'],
            'device_model_id'       => ['required', 'exists:device_models,id'],
            'bank_id'               => ['required', 'exists:banks,id'],
            'commission_profile_id' => ['required', 'exists:commission_profiles,id'],
    
            // ✅ required now
            'main_location_id'      => ['required', 'exists:main_locations,id'],
    
            // ✅ must belong to selected main location
            'charity_location_id'   => [
                'required',
                Rule::exists('charity_locations', 'id')->where(function ($q) use ($request) {
                    $q->where('main_location_id', $request->input('main_location_id'));
                }),
            ],
    
            'model_number'          => ['nullable', 'string', 'max:255'],
    
            // ✅ required for your logic + unique kiosk_id
            'kiosk_id'              => ['required', 'string', 'max:255', 'unique:devices,kiosk_id'],
            'terminal_id'           => ['required', 'string', 'max:255'],
    
            // optional
            'login_generated_token' => ['nullable', 'string', 'max:100', 'unique:devices,login_generated_token'],
    
            'status'                => ['required', Rule::in(['active', 'disabled', 'maintenance'])],
            'installed_at'          => ['nullable', 'date'],
        ]);
    
        // ✅ inherit location + company from main location
        $ml = MainLocation::select('id', 'company_id', 'country_id', 'region_id', 'district_id', 'city_id')
            ->find($validated['main_location_id']);
    
        if (!$ml) {
            return response()->json(['message' => 'Invalid main location'], 422);
        }
    
        // If you want company to always exist (recommended)
        if (empty($ml->company_id)) {
            return response()->json(['message' => 'Selected Main Location has no company_id. Please set company in Main Location first.'], 422);
        }
    
        $validated['companies_id'] = $ml->company_id;
        $validated['country_id']   = $ml->country_id;
        $validated['region_id']    = $ml->region_id;
        $validated['district_id']  = $ml->district_id;
        $validated['city_id']      = $ml->city_id;
    
        $device = Devices::create($validated);
    
        return response()->json([
            'success' => true,
            'device'  => $device->load([
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
            ]),
        ], 201);
    }

    public function update(Request $request, Devices $device)
    {
        $validated = $request->validate([
            'device_brand_id'       => ['required', 'exists:device_brands,id'],
            'device_model_id'       => ['required', 'exists:device_models,id'],
            'bank_id'               => ['required', 'exists:banks,id'],
            'commission_profile_id' => ['required', 'exists:commission_profiles,id'],
    
            'main_location_id'      => ['required', 'exists:main_locations,id'],
            'charity_location_id'   => [
                'required',
                Rule::exists('charity_locations', 'id')->where(function ($q) use ($request) {
                    $q->where('main_location_id', $request->input('main_location_id'));
                }),
            ],
    
            'model_number'          => ['nullable', 'string', 'max:255'],
    
            'kiosk_id'              => ['required', 'string', 'max:255', Rule::unique('devices', 'kiosk_id')->ignore($device->id)],
            'terminal_id'           => ['required', 'string', 'max:255'],
    
            'login_generated_token' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('devices', 'login_generated_token')->ignore($device->id),
            ],
    
            'status'                => ['required', Rule::in(['active', 'disabled', 'maintenance'])],
            'installed_at'          => ['nullable', 'date'],
        ]);
    
        $ml = MainLocation::select('id', 'company_id', 'country_id', 'region_id', 'district_id', 'city_id')
            ->find($validated['main_location_id']);
    
        if (!$ml) {
            return response()->json(['message' => 'Invalid main location'], 422);
        }
    
        if (empty($ml->company_id)) {
            return response()->json(['message' => 'Selected Main Location has no company_id. Please set company in Main Location first.'], 422);
        }
    
        $validated['companies_id'] = $ml->company_id;
        $validated['country_id']   = $ml->country_id;
        $validated['region_id']    = $ml->region_id;
        $validated['district_id']  = $ml->district_id;
        $validated['city_id']      = $ml->city_id;
    
        $device->update($validated);
    
        return response()->json([
            'success' => true,
            'device'  => $device->load([
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
            ]),
        ]);
    }


    public function export(Request $request): StreamedResponse
    {
        $sortBy  = (string) $request->query('sortBy', 'id');
        $sortDir = strtolower((string) $request->query('sortDir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $withSf  = filter_var($request->query('with_scalefusion', false), FILTER_VALIDATE_BOOL);
        

        if (!in_array($sortBy, ['id', 'kiosk_id', 'installed_at', 'status', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $filename = 'devices_export_' . now()->format('Ymd_His') . '.csv';

        // IMPORTANT: use the same query builder logic as index()
        $query = $this->buildDevicesQuery($request)
            ->orderBy($sortBy, $sortDir);

        return response()->streamDownload(function () use ($query, $withSf) {
            $out = fopen('php://output', 'w');

            // Header
            fputcsv($out, [
                'ID',
                'Kiosk ID',
                'Terminal ID',
                'DB Status',
                'Installed At',
                'Company',
                'Main Location',
                'Charity Location',
                'Country',
                'Region',
                'District',
                'City',
                'Brand',
                'Model',
                'Bank',
                // Scalefusion (optional)
                'SF Connection',
                'SF Locked',
                'SF Charging',
                'SF Battery',
                'SF Last Seen',
            ]);

            $query->chunk(500, function ($devices) use ($out, $withSf) {
                $sfMap = [];

                if ($withSf) {
                    $kioskIds = $devices->pluck('kiosk_id')->filter()->unique()->values()->all();
                    if (!empty($kioskIds)) {
                        $sfMap = app(\App\Services\ScalefusionService::class)->findDevicesByIds($kioskIds);
                    }
                }

                foreach ($devices as $d) {
                    $sf = $withSf ? ($sfMap[(string)($d->kiosk_id ?? '')] ?? null) : null;

                    fputcsv($out, [
                        $d->id,
                        $d->kiosk_id ?? '',
                        $d->terminal_id ?? '',
                        $d->status ?? '',
                        optional($d->installed_at)->toDateString() ?? ($d->installed_at ?? ''),
                        optional($d->company)->name ?? '',
                        optional($d->mainLocation)->name ?? '',
                        optional($d->charityLocation)->name ?? '',
                        optional($d->country)->name ?? '',
                        optional($d->region)->name ?? '',
                        optional($d->district)->name ?? '',
                        optional($d->city)->name ?? '',
                        optional($d->deviceBrand)->name ?? '',
                        optional($d->deviceModel)->name ?? '',
                        optional($d->bank)->name ?? '',
                        $sf['connection_status'] ?? '',
                        (!empty($sf['locked']) ? 'yes' : 'no'),
                        (!empty($sf['battery_charging']) ? 'yes' : 'no'),
                        isset($sf['battery_status']) ? $sf['battery_status'] : '',
                        $sf['last_seen_on'] ?? ($sf['last_connected_at'] ?? ''),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }


    private function buildDevicesQuery(Request $request): Builder
    {
        $search  = trim((string) $request->query('search', ''));

        $status          = $request->query('status'); // active/disabled/maintenance
        $countryId       = $request->query('country_id');
        $regionId        = $request->query('region_id');
        $districtId      = $request->query('district_id');
        $cityId          = $request->query('city_id');

        // NOTE: devices uses companies_id
        $companyId       = $request->query('company_id');
        $mainLocationId  = $request->query('main_location_id');
        $charityLocationId = $request->query('charity_location_id');

        $bankId          = $request->query('bank_id');
        $brandId         = $request->query('device_brand_id');
        $modelId         = $request->query('device_model_id');

        $missingKiosk    = filter_var($request->query('missing_kiosk', false), FILTER_VALIDATE_BOOL);
        $missingTerminal = filter_var($request->query('missing_terminal', false), FILTER_VALIDATE_BOOL);

        $installedFrom   = $request->query('installed_from'); // YYYY-MM-DD
        $installedTo     = $request->query('installed_to');   // YYYY-MM-DD

        // Donations
        $donationsDays   = (int) $request->query('donations_days', 0);
        $hasDonationsRaw = $request->query('has_donations', null);

        // Scalefusion quick filters
        $sfConnection = strtolower((string) $request->query('sf_connection', '')); // online/offline
        $sfLocked     = strtolower((string) $request->query('sf_locked', ''));     // locked/unlocked
        $sfCharging   = filter_var($request->query('sf_charging', false), FILTER_VALIDATE_BOOL);

        $query = \App\Models\Devices::query()
            ->with([
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
            ]);

        // DB filters
        if ($status) $query->where('status', $status);
        if ($countryId) $query->where('country_id', (int) $countryId);
        if ($regionId) $query->where('region_id', (int) $regionId);
        if ($districtId) $query->where('district_id', (int) $districtId);
        if ($cityId) $query->where('city_id', (int) $cityId);

        if ($companyId) $query->where('companies_id', (int) $companyId);
        if ($mainLocationId) $query->where('main_location_id', (int) $mainLocationId);
        if ($charityLocationId) $query->where('charity_location_id', (int) $charityLocationId);

        if ($bankId) $query->where('bank_id', (int) $bankId);
        if ($brandId) $query->where('device_brand_id', (int) $brandId);
        if ($modelId) $query->where('device_model_id', (int) $modelId);

        if ($missingKiosk) {
            $query->where(function ($q) {
                $q->whereNull('kiosk_id')->orWhere('kiosk_id', '');
            });
        }

        if ($missingTerminal) {
            $query->where(function ($q) {
                $q->whereNull('terminal_id')->orWhere('terminal_id', '');
            });
        }

        if ($installedFrom) $query->whereDate('installed_at', '>=', $installedFrom);
        if ($installedTo) $query->whereDate('installed_at', '<=', $installedTo);

        // Donations in last X days (exists/not exists)
        if ($donationsDays > 0 && $hasDonationsRaw !== null && $hasDonationsRaw !== '') {
            $hasDonations = filter_var($hasDonationsRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($hasDonations === null) $hasDonations = ((string)$hasDonationsRaw) === '1';

            $cutoff = now()->subDays($donationsDays);

            if ($hasDonations) {
                $query->whereExists(function ($sub) use ($cutoff) {
                    $sub->selectRaw('1')
                        ->from('charity_transactions')
                        ->whereColumn('charity_transactions.device_id', 'devices.id')
                        ->where('charity_transactions.created_at', '>=', $cutoff);
                });
            } else {
                $query->whereNotExists(function ($sub) use ($cutoff) {
                    $sub->selectRaw('1')
                        ->from('charity_transactions')
                        ->whereColumn('charity_transactions.device_id', 'devices.id')
                        ->where('charity_transactions.created_at', '>=', $cutoff);
                });
            }
        }

        // Search
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('kiosk_id', 'like', "%{$search}%")
                ->orWhere('terminal_id', 'like', "%{$search}%")
                ->orWhere('model_number', 'like', "%{$search}%")
                ->orWhere('login_generated_token', 'like', "%{$search}%");
            });
        }

        // Scalefusion filters (server-side) — only if requested
        $needsSfFiltering =
            in_array($sfConnection, ['online', 'offline'], true) ||
            in_array($sfLocked, ['locked', 'unlocked'], true) ||
            $sfCharging;

        if ($needsSfFiltering) {
            $kioskIds = (clone $query)
                ->whereNotNull('kiosk_id')
                ->where('kiosk_id', '<>', '')
                ->pluck('kiosk_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $sfMap = app(\App\Services\ScalefusionService::class)->findDevicesByIds($kioskIds);
            $sfKnownIds = array_keys($sfMap);

            $onlineIds = [];
            $lockedIds = [];
            $chargingIds = [];

            foreach ($sfMap as $kid => $sf) {
                $conn = strtolower((string) ($sf['connection_status'] ?? ''));
                if ($conn === 'online') $onlineIds[] = $kid;

                $isLocked = (bool) ($sf['locked'] ?? false);
                $ds = strtolower((string) ($sf['device_status'] ?? ''));
                if ($isLocked || str_contains($ds, 'lock')) $lockedIds[] = $kid;

                if (!empty($sf['battery_charging'])) $chargingIds[] = $kid;
            }

            if ($sfConnection === 'online') {
                $query->whereIn('kiosk_id', !empty($onlineIds) ? $onlineIds : ['__none__']);
            } elseif ($sfConnection === 'offline') {
                if (!empty($onlineIds)) {
                    $query->where(function ($q) use ($onlineIds) {
                        $q->whereNull('kiosk_id')
                        ->orWhere('kiosk_id', '')
                        ->orWhereNotIn('kiosk_id', $onlineIds);
                    });
                }
            }

            if ($sfLocked === 'locked') {
                $query->whereIn('kiosk_id', !empty($lockedIds) ? $lockedIds : ['__none__']);
            } elseif ($sfLocked === 'unlocked') {
                $unlocked = array_values(array_diff($sfKnownIds, $lockedIds));
                $query->whereIn('kiosk_id', !empty($unlocked) ? $unlocked : ['__none__']);
            }

            if ($sfCharging) {
                $query->whereIn('kiosk_id', !empty($chargingIds) ? $chargingIds : ['__none__']);
            }
        }

        return $query;
    }



    public function showByKiosk(string $kiosk_id)
{
    // kiosk_id in DB is stored as string, but device_id from Scalefusion is numeric.
    // Always compare as string to match DB.
    $kiosk_id = trim($kiosk_id);

    $device =  Devices::query()
        ->with([
            'deviceBrand',
            'deviceModel',
            'bank',
            'country',
            'region',
            'district',
            'city',
            'commissionProfile',
            'company:id,name',
            'mainLocation:id,name,company_id',
            'mainLocation.company:id,name',
            'charityLocation',
            'charityLocation.main_location',
        ])
        ->where('kiosk_id', $kiosk_id)
        ->first();

    if (!$device) {
        return response()->json([
            'success' => false,
            'message' => "Local device not found for kiosk_id: {$kiosk_id}",
        ], 404);
    }

    return response()->json([
        'success' => true,
        'device'  => $device,
    ]);
}

    /**
     * Delete a device.
     */
    public function destroy(Devices $device)
    {
        try {
            $device->delete();

            return response()->json([
                'message' => 'Device deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
