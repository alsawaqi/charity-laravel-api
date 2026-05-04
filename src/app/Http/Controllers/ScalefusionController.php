<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Country;
use App\Models\Devices;
use App\Models\MainLocation;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use App\Services\ScalefusionService;
use Throwable;
 
use Illuminate\Http\Client\Pool;

class ScalefusionController extends Controller
{
    private function token(): string
    {
        return (string) config('services.scalefusion.token');
    }

    private function v3(string $path): string
    {
        return rtrim(config('services.scalefusion.base_v3'), '/') . $path;
    }

    private function v1(string $path): string
    {
        return rtrim(config('services.scalefusion.base_v1'), '/') . $path;
    }

    private function client()
    {
        return Http::timeout(12)
            ->retry(2, 250, null, false)
            ->withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'Token ' . $this->token(),
            ]);
    }

    private function formArrayBody(string $key, array $values): string
    {
        return $this->formBody([$key => $values]);
    }

    private function formBody(array $fields): string
    {
        $pairs = [];

        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = [$key . '[]', $item];
                }

                continue;
            }

            $pairs[] = [$key, $value];
        }

        return collect($pairs)
            ->map(fn (array $field) => rawurlencode($field[0]) . '=' . rawurlencode($this->formValue($field[1])))
            ->implode('&');
    }

    private function formValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function queryArrayUrl(string $path, string $key, array $values): string
    {
        $query = collect($values)
            ->map(fn ($value) => rawurlencode($key . '[]') . '=' . rawurlencode((string) $value))
            ->implode('&');

        return $query === ''
            ? $this->v1($path)
            : $this->v1($path) . '?' . $query;
    }

    public function devices()
    {
        try {
            $res = $this->client()->get($this->v3('/devices.json'));
            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function device(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
        ]);

        try {
            $res = $this->client()->get($this->v3('/devices/' . $data['device_id'] . '.json'));
            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function deviceLocations(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
        ]);

        try {
            $res = $this->client()->get(
                $this->v1('/devices/' . $data['device_id'] . '/locations.json'),
                ['date' => $data['date']]
            );

            if (!$res->ok()) {
                return response()->json([
                    'message' => 'Failed to fetch device route from Scalefusion.',
                    'status' => $res->status(),
                    'raw' => $res->json(),
                ], $res->status());
            }

            $items = collect($res->json() ?? [])
                ->filter(fn ($row) => is_array($row))
                ->map(function (array $row) use ($data) {
                    $latitude = isset($row['latitude']) ? (float) $row['latitude'] : null;
                    $longitude = isset($row['longitude']) ? (float) $row['longitude'] : null;
                    $accuracy = isset($row['accuracy']) ? (float) $row['accuracy'] : null;
                    $dateTime = $row['date_time'] ?? null;

                    return [
                        'device_id' => (int) ($row['device_id'] ?? $row['deviceId'] ?? $data['device_id']),
                        'location_id' => isset($row['location_id']) ? (int) $row['location_id'] : null,
                        'address' => $row['address'] ?? null,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'accuracy' => $accuracy,
                        'date_time' => is_numeric($dateTime) ? (int) $dateTime : null,
                        'created_at_tz' => $row['created_at_tz'] ?? null,
                    ];
                })
                ->filter(fn ($row) => $row['latitude'] !== null && $row['longitude'] !== null)
                ->sortBy(fn ($row) => $row['date_time'] ?? $row['created_at_tz'] ?? 0)
                ->values();

            return response()->json([
                'device_id' => $data['device_id'],
                'date' => $data['date'],
                'count' => $items->count(),
                'items' => $items,
            ]);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'Scalefusion unreachable',
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    public function deviceAvailabilityFilters()
    {
        $countries = Country::with([
            'regions.districts.cities.mainLocations.company',
            'regions.districts.cities.mainLocations.organization',
            'regions.districts.cities.mainLocations.charityLocations.organization',
            'regions.districts.cities.charityLocations.organization',
        ])
            ->orderBy('name')
            ->get()
            ->map(function ($country) {
                return [
                    'id' => $country->id,
                    'name' => $country->name,
                    'regions' => $country->regions
                        ->sortBy('name')
                        ->map(function ($region) {
                            return [
                                'id' => $region->id,
                                'name' => $region->name,
                                'districts' => $region->districts
                                    ->sortBy('name')
                                    ->map(function ($district) {
                                        return [
                                            'id' => $district->id,
                                            'name' => $district->name,
                                            'cities' => $district->cities
                                                ->sortBy('name')
                                                ->map(function ($city) {
                                                    return [
                                                        'id' => $city->id,
                                                        'name' => $city->name,
                                                        'charity_locations' => $city->charityLocations
                                                            ->sortBy('name')
                                                            ->map(fn ($location) => $this->charityLocationOption($location))
                                                            ->values(),
                                                        'main_locations' => $city->mainLocations
                                                            ->sortBy('name')
                                                            ->map(fn ($mainLocation) => $this->mainLocationOption($mainLocation))
                                                            ->values(),
                                                    ];
                                                })
                                                ->values(),
                                        ];
                                    })
                                    ->values(),
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        $companies = Company::query()
            ->with([
                'mainLocations.company',
                'mainLocations.organization',
                'mainLocations.charityLocations.organization',
            ])
            ->orderBy('name')
            ->get()
            ->map(function ($company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'main_locations' => $company->mainLocations
                        ->sortBy('name')
                        ->map(fn ($mainLocation) => $this->mainLocationOption($mainLocation))
                        ->values(),
                ];
            })
            ->values();

        $organizations = Organization::query()
            ->orderBy('name')
            ->get()
            ->map(function ($organization) {
                $mainLocations = MainLocation::query()
                    ->with(['company', 'organization', 'charityLocations.organization'])
                    ->where('organization_id', $organization->id)
                    ->orWhereHas('charityLocations', fn ($location) => $location->where('organization_id', $organization->id))
                    ->orderBy('name')
                    ->get()
                    ->map(fn ($mainLocation) => $this->mainLocationOption($mainLocation))
                    ->values();

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'main_locations' => $mainLocations,
                ];
            })
            ->values();

        return response()->json([
            'countries' => $countries,
            'companies' => $companies,
            'organizations' => $organizations,
        ]);
    }

    public function deviceAvailabilities(Request $request)
    {
        $data = $request->validate([
            'from_date' => 'required|date_format:Y-m-d',
            'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date',
            'device_ids' => 'nullable|array',
            'device_ids.*' => 'integer',
            'country_id' => 'nullable|integer',
            'region_id' => 'nullable|integer',
            'district_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'main_location_id' => 'nullable|integer',
            'charity_location_id' => 'nullable|integer',
            'company_id' => 'nullable|integer',
            'organization_id' => 'nullable|integer',
            'search' => 'nullable|string|max:120',
            'sort_by' => 'nullable|in:inactive_seconds,active_seconds',
            'sort_dir' => 'nullable|in:asc,desc',
            'max_pages' => 'nullable|integer|min:1|max:25',
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from_date']);
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to_date']);

        if ($from->diffInDays($to) > 31) {
            return response()->json([
                'message' => 'Scalefusion device availability reports can be fetched for a maximum date range of one month.',
            ], 422);
        }

        $configuredMaxPages = max(1, (int) config('services.scalefusion.max_pages', 25));
        $maxPages = min((int) ($data['max_pages'] ?? $configuredMaxPages), $configuredMaxPages);
        $rows = collect();
        $page = 1;
        $pagesFetched = 0;
        $totalPages = 1;
        $lastMeta = [];
        $requestedDeviceIds = $this->availabilityRequestedDeviceIds($data);

        if ($requestedDeviceIds !== null && $requestedDeviceIds->isEmpty()) {
            return $this->emptyDeviceAvailabilityResponse($data);
        }

        try {
            do {
                $params = [
                    'from_date' => $data['from_date'],
                    'to_date' => $data['to_date'],
                    'page' => $page,
                ];

                $res = $this->client()->get(
                    $this->availabilityReportUrl($params, $requestedDeviceIds)
                );

                if ($res->status() === 429) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Scalefusion throttled the availability report request. Try again in a moment.',
                    ], 429);
                }

                if (!$res->ok()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to fetch device availability from Scalefusion.',
                        'status' => $res->status(),
                        'raw' => $res->json(),
                    ], 502);
                }

                $payload = $res->json() ?: [];
                $pageRows = collect($payload['devices'] ?? [])
                    ->filter(fn ($row) => is_array($row))
                    ->values();

                $rows = $rows->merge($pageRows);
                $totalPages = max(1, (int) ($payload['total_pages'] ?? 1));
                $lastMeta = $payload;
                $page++;
                $pagesFetched++;
            } while ($page <= $totalPages && $pagesFetched < $maxPages);
        } catch (ConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Scalefusion unreachable',
                'error' => $e->getMessage(),
            ], 503);
        }

        if ($requestedDeviceIds !== null) {
            $allowedDeviceIds = $requestedDeviceIds->flip();

            $rows = $rows
                ->filter(fn (array $row) => $allowedDeviceIds->has((string) ($row['device_id'] ?? '')))
                ->values();
        }

        $scalefusionDeviceIds = $rows
            ->pluck('device_id')
            ->filter(fn ($deviceId) => filled($deviceId))
            ->map(fn ($deviceId) => (string) $deviceId)
            ->unique()
            ->values();

        $localDevices = Devices::query()
            ->with([
                'DeviceBrand',
                'DeviceModel',
                'bank',
                'country',
                'region',
                'district',
                'city',
                'mainLocation.company',
                'mainLocation.organization',
                'charityLocation.organization',
                'charityLocation.mainLocation.company',
            ])
            ->whereIn('kiosk_id', $scalefusionDeviceIds)
            ->get()
            ->keyBy(fn (Devices $device) => (string) $device->kiosk_id);

        $devices = $rows
            ->groupBy(fn (array $row) => (string) ($row['device_id'] ?? 'unknown'))
            ->map(function ($segments, string $deviceId) use ($localDevices) {
                $localDevice = $localDevices->get($deviceId);
                $normalizedSegments = $segments
                    ->map(fn (array $row) => $this->normalizeAvailabilitySegment($row))
                    ->sortBy(fn (array $row) => $row['from_at'] ?? $row['from_date'] ?? '')
                    ->values();

                $activeSeconds = (int) $normalizedSegments
                    ->where('availability_status', 'active')
                    ->sum('duration_in_seconds');
                $inactiveSeconds = (int) $normalizedSegments
                    ->where('availability_status', 'inactive')
                    ->sum('duration_in_seconds');

                $firstSegment = $normalizedSegments->first() ?? [];

                return [
                    'device_id' => is_numeric($deviceId) ? (int) $deviceId : $deviceId,
                    'device_name' => $firstSegment['device_name'] ?? null,
                    'linked' => $localDevice !== null,
                    'local_device' => $this->localDevicePayload($localDevice),
                    'summary' => [
                        'segments' => $normalizedSegments->count(),
                        'active_seconds' => $activeSeconds,
                        'inactive_seconds' => $inactiveSeconds,
                        'active_duration' => $this->durationLabel($activeSeconds),
                        'inactive_duration' => $this->durationLabel($inactiveSeconds),
                    ],
                    'segments' => $normalizedSegments,
                ];
            });

        $devices = $this->sortAvailabilityDevices(
            $devices,
            $data['sort_by'] ?? null,
            $data['sort_dir'] ?? 'desc'
        );

        $activeSeconds = (int) $devices->sum(fn (array $device) => $device['summary']['active_seconds']);
        $inactiveSeconds = (int) $devices->sum(fn (array $device) => $device['summary']['inactive_seconds']);
        $linkedDevices = $devices->where('linked', true)->count();

        return response()->json([
            'success' => true,
            'range' => [
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
            ],
            'summary' => [
                'total_devices' => $devices->count(),
                'linked_devices' => $linkedDevices,
                'unlinked_devices' => $devices->count() - $linkedDevices,
                'segments' => $rows->count(),
                'active_seconds' => $activeSeconds,
                'inactive_seconds' => $inactiveSeconds,
                'active_duration' => $this->durationLabel($activeSeconds),
                'inactive_duration' => $this->durationLabel($inactiveSeconds),
            ],
            'meta' => [
                'scalefusion_total_count' => $lastMeta['total_count'] ?? null,
                'current_page' => $lastMeta['current_page'] ?? null,
                'total_pages' => $totalPages,
                'pages_fetched' => $pagesFetched,
                'truncated' => $pagesFetched < $totalPages,
            ],
            'devices' => $devices,
        ]);
    }

    private function sortAvailabilityDevices($devices, ?string $sortBy, string $sortDirection)
    {
        if (in_array($sortBy, ['inactive_seconds', 'active_seconds'], true)) {
            $direction = strtolower($sortDirection) === 'asc' ? 1 : -1;

            return $devices
                ->sort(function (array $left, array $right) use ($sortBy, $direction) {
                    $leftSeconds = (int) ($left['summary'][$sortBy] ?? 0);
                    $rightSeconds = (int) ($right['summary'][$sortBy] ?? 0);
                    $secondsComparison = ($leftSeconds <=> $rightSeconds) * $direction;

                    if ($secondsComparison !== 0) {
                        return $secondsComparison;
                    }

                    return strcasecmp($this->availabilityDeviceSortName($left), $this->availabilityDeviceSortName($right));
                })
                ->values();
        }

        return $devices
            ->sortBy(fn (array $device) => sprintf(
                '%d-%s',
                $device['linked'] ? 0 : 1,
                strtolower($this->availabilityDeviceSortName($device))
            ))
            ->values();
    }

    private function availabilityDeviceSortName(array $device): string
    {
        return (string) ($device['device_name'] ?? $device['device_id'] ?? '');
    }

    private function normalizeAvailabilitySegment(array $row): array
    {
        $duration = max(0, (int) ($row['duration_in_seconds'] ?? 0));

        return [
            'device_id' => isset($row['device_id']) && is_numeric($row['device_id']) ? (int) $row['device_id'] : $row['device_id'] ?? null,
            'device_name' => $row['device_name'] ?? null,
            'date' => $row['date'] ?? null,
            'from_date' => $row['from_date'] ?? null,
            'to_date' => $row['to_date'] ?? null,
            'from_at' => $this->parseAvailabilityTimestamp($row['from_date'] ?? null),
            'to_at' => $this->parseAvailabilityTimestamp($row['to_date'] ?? null),
            'availability_status' => strtolower((string) ($row['availability_status'] ?? 'unknown')),
            'duration_in_seconds' => $duration,
            'duration_label' => $this->durationLabel($duration),
        ];
    }

    private function availabilityReportUrl(array $params, $deviceIds = null): string
    {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        if ($deviceIds !== null) {
            $deviceQuery = collect($deviceIds)
                ->filter(fn ($deviceId) => filled($deviceId))
                ->map(fn ($deviceId) => rawurlencode('device_ids[]') . '=' . rawurlencode((string) $deviceId))
                ->implode('&');

            if ($deviceQuery !== '') {
                $query = $query === '' ? $deviceQuery : $query . '&' . $deviceQuery;
            }
        }

        $url = $this->v1('/reports/device_availabilities.json');

        return $query === '' ? $url : $url . '?' . $query;
    }

    private function availabilityRequestedDeviceIds(array $data)
    {
        $hasLocalFilters = $this->hasAvailabilityLocalFilters($data);
        $requestedDeviceIds = null;

        if ($hasLocalFilters) {
            $requestedDeviceIds = $this->availabilityLocalDeviceQuery($data)
                ->pluck('kiosk_id')
                ->filter()
                ->map(fn ($deviceId) => (string) $deviceId)
                ->unique()
                ->values();
        }

        if (!empty($data['device_ids'])) {
            $explicitDeviceIds = collect($data['device_ids'])
                ->map(fn ($deviceId) => (string) $deviceId)
                ->unique()
                ->values();

            $requestedDeviceIds = $requestedDeviceIds === null
                ? $explicitDeviceIds
                : $requestedDeviceIds->intersect($explicitDeviceIds)->values();
        }

        return $requestedDeviceIds;
    }

    private function hasAvailabilityLocalFilters(array $data): bool
    {
        foreach ([
            'country_id',
            'region_id',
            'district_id',
            'city_id',
            'main_location_id',
            'charity_location_id',
            'company_id',
            'organization_id',
            'search',
        ] as $field) {
            if (filled($data[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function availabilityLocalDeviceQuery(array $data)
    {
        $query = Devices::query()
            ->whereNotNull('kiosk_id')
            ->where('kiosk_id', '!=', '');

        foreach ([
            'country_id',
            'region_id',
            'district_id',
            'city_id',
            'main_location_id',
            'charity_location_id',
        ] as $field) {
            if (filled($data[$field] ?? null)) {
                $query->where($field, $data[$field]);
            }
        }

        if (filled($data['company_id'] ?? null)) {
            $companyId = (int) $data['company_id'];

            $query->where(function ($inner) use ($companyId) {
                $inner->where('companies_id', $companyId)
                    ->orWhereHas('mainLocation', fn ($location) => $location->where('company_id', $companyId))
                    ->orWhereHas('charityLocation.mainLocation', fn ($location) => $location->where('company_id', $companyId));
            });
        }

        if (filled($data['organization_id'] ?? null)) {
            $organizationIds = $this->organizationIdsForFilter((int) $data['organization_id']);

            $query->where(function ($inner) use ($organizationIds) {
                $inner->whereHas('mainLocation', fn ($location) => $location->whereIn('organization_id', $organizationIds))
                    ->orWhereHas('charityLocation', fn ($location) => $location->whereIn('organization_id', $organizationIds))
                    ->orWhereHas('charityLocation.mainLocation', fn ($location) => $location->whereIn('organization_id', $organizationIds));
            });
        }

        if (filled($data['search'] ?? null)) {
            $search = trim((string) $data['search']);

            $query->where(function ($inner) use ($search) {
                $inner->where('kiosk_id', 'like', "%{$search}%")
                    ->orWhere('terminal_id', 'like', "%{$search}%")
                    ->orWhere('model_number', 'like', "%{$search}%")
                    ->orWhereHas('country', fn ($relation) => $relation->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('region', fn ($relation) => $relation->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('district', fn ($relation) => $relation->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('city', fn ($relation) => $relation->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('mainLocation', fn ($relation) => $relation->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('charityLocation', fn ($relation) => $relation->where('name', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    private function organizationIdsForFilter(int $organizationId): array
    {
        $organization = Organization::with('children.descendants')->find($organizationId);

        if (!$organization) {
            return [$organizationId];
        }

        return $organization->descendantsAndSelfIds();
    }

    private function emptyDeviceAvailabilityResponse(array $data)
    {
        return response()->json([
            'success' => true,
            'range' => [
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
            ],
            'summary' => [
                'total_devices' => 0,
                'linked_devices' => 0,
                'unlinked_devices' => 0,
                'segments' => 0,
                'active_seconds' => 0,
                'inactive_seconds' => 0,
                'active_duration' => $this->durationLabel(0),
                'inactive_duration' => $this->durationLabel(0),
            ],
            'meta' => [
                'scalefusion_total_count' => 0,
                'current_page' => null,
                'total_pages' => 0,
                'pages_fetched' => 0,
                'truncated' => false,
            ],
            'devices' => [],
        ]);
    }

    private function charityLocationOption($location): ?array
    {
        if (!$location) {
            return null;
        }

        return [
            'id' => $location->id,
            'name' => $location->name,
            'organization_id' => $location->organization_id,
            'organization' => $this->simplePayload($location->organization),
        ];
    }

    private function mainLocationOption($mainLocation): ?array
    {
        if (!$mainLocation) {
            return null;
        }

        return [
            'id' => $mainLocation->id,
            'name' => $mainLocation->name,
            'company_id' => $mainLocation->company_id,
            'organization_id' => $mainLocation->organization_id,
            'company' => $this->simplePayload($mainLocation->company),
            'organization' => $this->simplePayload($mainLocation->organization),
            'charity_locations' => $mainLocation->charityLocations
                ->sortBy('name')
                ->map(fn ($location) => $this->charityLocationOption($location))
                ->values(),
        ];
    }

    private function parseAvailabilityTimestamp(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function localDevicePayload(?Devices $device): ?array
    {
        if (!$device) {
            return null;
        }

        return [
            'id' => $device->id,
            'kiosk_id' => $device->kiosk_id,
            'terminal_id' => $device->terminal_id,
            'model_number' => $device->model_number,
            'status' => $device->status,
            'installed_at' => optional($device->installed_at)->toDateString(),
            'location_label' => $this->locationLabel($device),
            'device_brand' => $this->relatedPayload($device, 'DeviceBrand'),
            'device_model' => $this->relatedPayload($device, 'DeviceModel'),
            'bank' => $this->relatedPayload($device, 'bank'),
            'company' => $this->companyPayload($device),
            'organization' => $this->organizationPayload($device),
            'country' => $this->relatedPayload($device, 'country'),
            'region' => $this->relatedPayload($device, 'region'),
            'district' => $this->relatedPayload($device, 'district'),
            'city' => $this->relatedPayload($device, 'city'),
            'main_location' => $this->relatedPayload($device, 'mainLocation'),
            'charity_location' => $this->relatedPayload($device, 'charityLocation'),
        ];
    }

    private function relatedPayload(Devices $device, string $relation): ?array
    {
        return $this->simplePayload($device->getRelationValue($relation));
    }

    private function simplePayload($related): ?array
    {
        if (!$related) {
            return null;
        }

        return [
            'id' => $related->id,
            'name' => $related->name ?? $related->bank_name ?? $related->title ?? null,
        ];
    }

    private function companyPayload(Devices $device): ?array
    {
        $mainLocation = $device->getRelationValue('mainLocation');
        $charityLocation = $device->getRelationValue('charityLocation');

        return $this->simplePayload(
            $mainLocation?->getRelationValue('company')
                ?? $charityLocation?->getRelationValue('mainLocation')?->getRelationValue('company')
        );
    }

    private function organizationPayload(Devices $device): ?array
    {
        $mainLocation = $device->getRelationValue('mainLocation');
        $charityLocation = $device->getRelationValue('charityLocation');

        return $this->simplePayload(
            $charityLocation?->getRelationValue('organization')
                ?? $mainLocation?->getRelationValue('organization')
                ?? $charityLocation?->getRelationValue('mainLocation')?->getRelationValue('organization')
        );
    }

    private function locationLabel(Devices $device): ?string
    {
        $parts = collect([
            $device->getRelationValue('mainLocation')?->name,
            $device->getRelationValue('charityLocation')?->name,
            $device->getRelationValue('country')?->name,
            $device->getRelationValue('region')?->name,
            $device->getRelationValue('district')?->name,
            $device->getRelationValue('city')?->name,
        ])
            ->filter()
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(' / ');
    }

    private function durationLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        $parts = [];

        if ($days > 0) {
            $parts[] = $days . 'd';
        }

        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }

        if ($minutes > 0 || empty($parts)) {
            $parts[] = $minutes . 'm';
        }

        return implode(' ', $parts);
    }

    public function reboot(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
        ]);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put($this->v1('/devices/' . $data['device_id'] . '/reboot.json'), []);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function alarm(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
        ]);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->v1('/devices/' . $data['device_id'] . '/send_alarm.json'), []);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function broadcastMessage(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer|min:1',
            'sender_name' => 'required|string|max:100',
            'message_body' => 'required|string|max:1000',
            'keep_ringing' => 'nullable|boolean',
            'show_as_dialog' => 'nullable|boolean',
        ]);

        $body = $this->formBody([
            'device_ids' => [$data['device_id']],
            'sender_name' => trim($data['sender_name']),
            'message_body' => trim($data['message_body']),
            'keep_ringing' => $request->boolean('keep_ringing', true),
            'show_as_dialog' => $request->boolean('show_as_dialog', true),
        ]);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/broadcast_message.json'), [
                    'body' => $body,
                ]);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function action(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer|min:1',
            'action_type' => 'required|string|in:screen_lock,shutdown,reboot,mark_as_lost,mark_as_found,factory_reset,delete_device,buzz_device,rotate_filevault_key',
            'lost_mode_message' => 'nullable|string|max:500',
            'lost_mode_footnote' => 'nullable|string|max:500',
            'lost_mode_phone' => 'nullable|string|max:100',
            'wipe_sd_card' => 'nullable|boolean',
        ]);

        $bodyFields = [
            'action_type' => $data['action_type'],
            'wipe_sd_card' => $request->boolean('wipe_sd_card', false),
        ];

        foreach (['lost_mode_message', 'lost_mode_footnote', 'lost_mode_phone'] as $field) {
            if (filled($data[$field] ?? null)) {
                $bodyFields[$field] = trim($data[$field]);
            }
        }

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->queryArrayUrl('/devices/actions.json', 'device_ids', [$data['device_id']]), [
                    'body' => $this->formBody($bodyFields),
                ]);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function clearAppData(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer|min:1',
        ]);

        $body = $this->formArrayBody('device_ids', [$data['device_id']]);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/clear_app_data.json'), [
                    'body' => $body,
                ]);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/scalefusion/devices/lock
     * body: { "device_ids": [1,2,3] }
     */
    public function lock(Request $request)
    {
        $data = $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer',
        ]);

        $body = $this->formArrayBody('device_ids', $data['device_ids']);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/lock.json'), [
                    'body' => $body,
                ]);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/scalefusion/devices/unlock
     * body: { "device_ids": [1,2,3] }
     */
    public function unlock(Request $request)
    {
        $data = $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer',
        ]);

        $body = $this->formArrayBody('device_ids', $data['device_ids']);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/unlock.json'), [
                    'body' => $body,
                ]);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }


    public function locationGeofence(Request $request, ScalefusionService $sf)
    {
        $data = $request->validate([
            'cursor' => 'nullable|integer',
            'only'   => 'nullable|string', // e.g. "online" or "online,active"
            'hide_no_location' => 'nullable|boolean',
        ]);
    
        $cursor = $data['cursor'] ?? null;
        $only = strtolower(trim((string)($data['only'] ?? '')));
        $onlySet = collect(explode(',', $only))
            ->map(fn($s) => strtolower(trim($s)))
            ->filter()
            ->unique();
    
        $hideNoLocation = filter_var($request->query('hide_no_location', false), FILTER_VALIDATE_BOOL);
    
        // 1) Fetch ONE page only (cursor pagination)
        $params = [];
        if (!empty($cursor)) $params['cursor'] = $cursor;
    
        $res = $this->client()->get($this->v1('/devices/location_geofence.json'), $params);
    
        // Handle 429 safely (don’t throw RequestException)
        if ($res->status() === 429) {
            return response()->json([
                'success' => false,
                'message' => 'Scalefusion throttled (429). Try again in a moment.',
            ], 429);
        }
    
        if (!$res->ok()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch location geofence from Scalefusion.',
                'status'  => $res->status(),
                'raw'     => $res->json(),
            ], 502);
        }
    
        $geo = $res->json();
        $geoDevices = $geo['devices'] ?? [];
    
        // 2) Get cached summary map from v3/devices.json (few calls, cached)
        $summaryMap = $sf->getDevicesSummaryMapCached(120);
    
        // 3) Merge + server filter "only"
        $out = [];
        $counts = [
            'total' => 0,
            'online' => 0,
            'offline' => 0,
            'active' => 0,
            'inactive' => 0,
            'locked' => 0,
            'charging' => 0,
            'no_location' => 0,
        ];
    
        foreach ($geoDevices as $d) {
            $id = (string)($d['device_id'] ?? '');
            if ($id === '') continue;
    
            $loc = $d['location'] ?? [];
            $lat = $loc['latitude'] ?? null;
            $lng = $loc['longitude'] ?? null;
    
            if ($hideNoLocation && (empty($lat) || empty($lng))) continue;
    
            $det = $summaryMap[$id] ?? [];
    
            $cs = strtolower((string)($det['connection_status'] ?? ''));
            $st = strtolower((string)($det['connection_state'] ?? ''));
    
            // server filtering:
            $ok = true;
    
            if ($onlySet->contains('online'))   $ok = $ok && ($cs === 'online');
            if ($onlySet->contains('offline'))  $ok = $ok && ($cs === 'offline');
    
            if ($onlySet->contains('active'))   $ok = $ok && ($st === 'active');
            if ($onlySet->contains('inactive')) $ok = $ok && ($st === 'inactive');
    
            if ($onlySet->contains('locked'))   $ok = $ok && !empty($det['locked']);
            if ($onlySet->contains('unlocked')) $ok = $ok && empty($det['locked']);
    
            if ($onlySet->contains('charging')) $ok = $ok && !empty($det['battery_charging']);
    
            if (!$ok) continue;
    
            // counts for returned page
            $counts['total']++;
            if ($cs === 'online') $counts['online']++;
            if ($cs === 'offline') $counts['offline']++;
            if ($st === 'active') $counts['active']++;
            if ($st === 'inactive') $counts['inactive']++;
            if (!empty($det['locked'])) $counts['locked']++;
            if (!empty($det['battery_charging'])) $counts['charging']++;
            if (empty($lat) || empty($lng)) $counts['no_location']++;
    
            $out[] = [
                'device_id' => (int)$id,
                'name'      => $d['name'] ?? ($det['name'] ?? null),
                'imei_no'   => $d['imei_no'] ?? null,
                'serial_no' => $d['serial_no'] ?? null,
                'location' => [
                    'lat'        => $lat,
                    'lng'        => $lng,
                    'address'    => $loc['address'] ?? null,
                    'date_time'  => $loc['date_time'] ?? null,
                    'created_at' => $loc['created_at_tz'] ?? null,
                ],
                'connection_state'  => $det['connection_state'] ?? null,
                'connection_status' => $det['connection_status'] ?? null,
                'battery_status'    => $det['battery_status'] ?? null,
                'battery_charging'  => $det['battery_charging'] ?? null,
                'locked'            => $det['locked'] ?? null,
                'last_seen_on'      => $det['last_seen_on'] ?? null,
                'last_connected_at' => $det['last_connected_at'] ?? null,
            ];
        }
    
        return response()->json([
            'success' => true,
            'total_count'    => $geo['total_count'] ?? null,
            'current_cursor' => $geo['current_cursor'] ?? $cursor,
            'next_cursor'    => $geo['next_cursor'] ?? null,
            'counts'         => $counts,
            'devices'        => $out,
        ]);
    }

 
}
