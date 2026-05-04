<?php

namespace Tests\Feature;

use App\Models\DashboardPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScalefusionDeviceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAvailabilityUser(): void
    {
        $user = User::factory()->create();
        $permission = DashboardPermission::query()
            ->where('key', 'dashboard.device-availability.view')
            ->firstOrFail();

        $user->dashboardDirectPermissions()->attach($permission->id);

        Sanctum::actingAs($user);
    }

    private function seedDeviceCatalog(): void
    {
        $now = now();

        DB::table('device_brands')->insertOrIgnore([
            'id' => 1,
            'name' => 'Samsung',
            'slug' => 'samsung',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('device_models')->insertOrIgnore([
            'id' => 1,
            'device_brand_id' => 1,
            'name' => 'Galaxy Tab',
            'model_number' => 'SM-T500',
            'device_type' => 'tablet',
            'os' => 'android',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedLocationHierarchy(): void
    {
        $now = now();

        DB::table('countries')->insertOrIgnore([
            'id' => 1,
            'name' => 'Oman',
            'iso_code' => 'OM',
            'phone_code' => '+968',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('regions')->insertOrIgnore([
            'id' => 1,
            'country_id' => 1,
            'name' => 'Muscat',
            'type' => 'governorate',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('districts')->insertOrIgnore([
            'id' => 1,
            'region_id' => 1,
            'name' => 'Bawshar',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('cities')->insertOrIgnore([
            'id' => 1,
            'region_id' => 1,
            'district_id' => 1,
            'name' => 'Al Khuwair',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('companies')->insertOrIgnore([
            'id' => 1,
            'name' => 'First Company',
            'email' => 'first@example.com',
            'phone_number' => '+968111',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('companies')->insertOrIgnore([
            'id' => 2,
            'name' => 'Second Company',
            'email' => 'second@example.com',
            'phone_number' => '+968222',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('organizations')->insertOrIgnore([
            'id' => 1,
            'name' => 'Oman Charity',
            'country_id' => 1,
            'region_id' => 1,
            'city_id' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('main_locations')->insertOrIgnore([
            'id' => 1,
            'country_id' => 1,
            'region_id' => 1,
            'district_id' => 1,
            'city_id' => 1,
            'organization_id' => 1,
            'company_id' => 1,
            'name' => 'Grand Mall',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('main_locations')->insertOrIgnore([
            'id' => 2,
            'country_id' => 1,
            'region_id' => 1,
            'district_id' => 1,
            'city_id' => 1,
            'organization_id' => 1,
            'company_id' => 2,
            'name' => 'Airport Hall',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('charity_locations')->insertOrIgnore([
            'id' => 1,
            'country_id' => 1,
            'region_id' => 1,
            'district_id' => 1,
            'city_id' => 1,
            'main_location_id' => 1,
            'organization_id' => 1,
            'name' => 'Donation Desk A',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_user_can_fetch_device_availability_linked_to_local_devices(): void
    {
        $now = now();

        $this->seedDeviceCatalog();
        $this->seedLocationHierarchy();

        DB::table('devices')->insert([
            'id' => 1,
            'device_brand_id' => 1,
            'device_model_id' => 1,
            'country_id' => 1,
            'region_id' => 1,
            'district_id' => 1,
            'city_id' => 1,
            'main_location_id' => 1,
            'charity_location_id' => 1,
            'kiosk_id' => '6546752',
            'terminal_id' => 'TERM-001',
            'model_number' => 'Local Model',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Http::fake([
            'https://api.scalefusion.com/api/v1/reports/device_availabilities.json*' => Http::response([
                'devices' => [
                    [
                        'device_id' => 6546752,
                        'device_name' => 'Tablet Masjid',
                        'date' => '2026-02-06',
                        'from_date' => '06-Feb-2026 05:24:02 AM UTC',
                        'to_date' => '06-Feb-2026 04:23:45 PM UTC',
                        'availability_status' => 'active',
                        'duration_in_seconds' => 39583,
                    ],
                    [
                        'device_id' => 6546752,
                        'device_name' => 'Tablet Masjid',
                        'date' => '2026-02-06',
                        'from_date' => '06-Feb-2026 04:23:46 PM UTC',
                        'to_date' => '08-Feb-2026 11:59:59 PM UTC',
                        'availability_status' => 'inactive',
                        'duration_in_seconds' => 200173,
                    ],
                ],
                'total_count' => 2,
                'current_page' => 1,
                'prev_page' => null,
                'next_page' => null,
                'total_pages' => 1,
            ], 200),
        ]);

        $this->actingAsAvailabilityUser();

        $response = $this->getJson('/api/scalefusion/device-availabilities?from_date=2026-02-06&to_date=2026-02-08');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_devices', 1)
            ->assertJsonPath('summary.linked_devices', 1)
            ->assertJsonPath('summary.segments', 2)
            ->assertJsonPath('devices.0.device_id', 6546752)
            ->assertJsonPath('devices.0.device_name', 'Tablet Masjid')
            ->assertJsonPath('devices.0.linked', true)
            ->assertJsonPath('devices.0.local_device.id', 1)
            ->assertJsonPath('devices.0.local_device.kiosk_id', '6546752')
            ->assertJsonPath('devices.0.local_device.terminal_id', 'TERM-001')
            ->assertJsonPath('devices.0.local_device.country.name', 'Oman')
            ->assertJsonPath('devices.0.summary.active_seconds', 39583)
            ->assertJsonPath('devices.0.summary.inactive_seconds', 200173)
            ->assertJsonCount(2, 'devices.0.segments');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/reports/device_availabilities.json')
            && str_contains($request->url(), 'from_date=2026-02-06')
            && str_contains($request->url(), 'to_date=2026-02-08'));
    }

    public function test_user_can_fetch_device_availability_filter_options(): void
    {
        $this->seedLocationHierarchy();
        $this->actingAsAvailabilityUser();

        $this->getJson('/api/scalefusion/device-availability/filters')
            ->assertOk()
            ->assertJsonPath('companies.0.name', 'First Company')
            ->assertJsonPath('companies.0.main_locations.0.name', 'Grand Mall')
            ->assertJsonPath('organizations.0.name', 'Oman Charity')
            ->assertJsonPath('countries.0.name', 'Oman')
            ->assertJsonPath('countries.0.regions.0.name', 'Muscat')
            ->assertJsonPath('countries.0.regions.0.districts.0.name', 'Bawshar')
            ->assertJsonPath('countries.0.regions.0.districts.0.cities.0.name', 'Al Khuwair')
            ->assertJsonFragment(['name' => 'Grand Mall'])
            ->assertJsonFragment(['name' => 'Donation Desk A']);
    }

    public function test_company_filter_limits_scalefusion_availability_request_to_matching_local_devices(): void
    {
        $now = now();

        $this->seedDeviceCatalog();
        $this->seedLocationHierarchy();

        DB::table('devices')->insert([
            [
                'id' => 1,
                'device_brand_id' => 1,
                'device_model_id' => 1,
                'country_id' => 1,
                'region_id' => 1,
                'district_id' => 1,
                'city_id' => 1,
                'main_location_id' => 1,
                'charity_location_id' => 1,
                'kiosk_id' => '6546752',
                'terminal_id' => 'TERM-001',
                'model_number' => 'Local Model',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'device_brand_id' => 1,
                'device_model_id' => 1,
                'country_id' => 1,
                'region_id' => 1,
                'district_id' => 1,
                'city_id' => 1,
                'main_location_id' => 2,
                'charity_location_id' => null,
                'kiosk_id' => '9999999',
                'terminal_id' => 'TERM-002',
                'model_number' => 'Other Model',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Http::fake([
            'https://api.scalefusion.com/api/v1/reports/device_availabilities.json*' => Http::response([
                'devices' => [
                    [
                        'device_id' => 6546752,
                        'device_name' => 'Tablet Masjid',
                        'date' => '2026-02-06',
                        'from_date' => '06-Feb-2026 05:24:02 AM UTC',
                        'to_date' => '06-Feb-2026 04:23:45 PM UTC',
                        'availability_status' => 'active',
                        'duration_in_seconds' => 39583,
                    ],
                ],
                'total_count' => 1,
                'current_page' => 1,
                'total_pages' => 1,
            ], 200),
        ]);

        $this->actingAsAvailabilityUser();

        $this->getJson('/api/scalefusion/device-availabilities?from_date=2026-02-06&to_date=2026-02-08&company_id=1')
            ->assertOk()
            ->assertJsonPath('summary.total_devices', 1)
            ->assertJsonPath('devices.0.local_device.company.name', 'First Company');

        Http::assertSent(function ($request) {
            $url = $request->url();
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            return ($query['device_ids'] ?? []) === ['6546752']
                && str_contains($url, 'device_ids%5B%5D=6546752')
                && !str_contains($url, 'device_ids%5B0%5D=');
        });
    }

    public function test_scalefusion_server_error_returns_bad_gateway_response(): void
    {
        Http::fake([
            'https://api.scalefusion.com/api/v1/reports/device_availabilities.json*' => Http::response([
                'status' => 500,
                'error' => 'Internal Server Error',
            ], 500),
        ]);

        $this->actingAsAvailabilityUser();

        $this->getJson('/api/scalefusion/device-availabilities?from_date=2026-02-06&to_date=2026-02-08')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to fetch device availability from Scalefusion.');
    }

    public function test_user_can_sort_device_availability_by_most_inactive_time(): void
    {
        $now = now();

        $this->seedDeviceCatalog();
        $this->seedLocationHierarchy();

        DB::table('devices')->insert([
            [
                'id' => 1,
                'device_brand_id' => 1,
                'device_model_id' => 1,
                'country_id' => 1,
                'region_id' => 1,
                'district_id' => 1,
                'city_id' => 1,
                'main_location_id' => 1,
                'charity_location_id' => 1,
                'kiosk_id' => '1111111',
                'terminal_id' => 'TERM-ACTIVE',
                'model_number' => 'Active Model',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'device_brand_id' => 1,
                'device_model_id' => 1,
                'country_id' => 1,
                'region_id' => 1,
                'district_id' => 1,
                'city_id' => 1,
                'main_location_id' => 1,
                'charity_location_id' => 1,
                'kiosk_id' => '2222222',
                'terminal_id' => 'TERM-INACTIVE',
                'model_number' => 'Inactive Model',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Http::fake([
            'https://api.scalefusion.com/api/v1/reports/device_availabilities.json*' => Http::response([
                'devices' => [
                    [
                        'device_id' => 1111111,
                        'device_name' => 'Mostly Active',
                        'date' => '2026-02-06',
                        'from_date' => '06-Feb-2026 08:00:00 AM UTC',
                        'to_date' => '06-Feb-2026 08:16:40 AM UTC',
                        'availability_status' => 'active',
                        'duration_in_seconds' => 1000,
                    ],
                    [
                        'device_id' => 1111111,
                        'device_name' => 'Mostly Active',
                        'date' => '2026-02-06',
                        'from_date' => '06-Feb-2026 08:16:41 AM UTC',
                        'to_date' => '06-Feb-2026 08:16:51 AM UTC',
                        'availability_status' => 'inactive',
                        'duration_in_seconds' => 10,
                    ],
                    [
                        'device_id' => 2222222,
                        'device_name' => 'Mostly Inactive',
                        'date' => '2026-02-06',
                        'from_date' => '06-Feb-2026 08:00:00 AM UTC',
                        'to_date' => '06-Feb-2026 10:30:00 AM UTC',
                        'availability_status' => 'inactive',
                        'duration_in_seconds' => 9000,
                    ],
                    [
                        'device_id' => 2222222,
                        'device_name' => 'Mostly Inactive',
                        'date' => '2026-02-06',
                        'from_date' => '06-Feb-2026 10:30:01 AM UTC',
                        'to_date' => '06-Feb-2026 10:30:11 AM UTC',
                        'availability_status' => 'active',
                        'duration_in_seconds' => 10,
                    ],
                ],
                'total_count' => 4,
                'current_page' => 1,
                'total_pages' => 1,
            ], 200),
        ]);

        $this->actingAsAvailabilityUser();

        $this->getJson('/api/scalefusion/device-availabilities?from_date=2026-02-06&to_date=2026-02-08&sort_by=inactive_seconds')
            ->assertOk()
            ->assertJsonPath('devices.0.device_id', 2222222)
            ->assertJsonPath('devices.0.summary.inactive_seconds', 9000)
            ->assertJsonPath('devices.1.device_id', 1111111)
            ->assertJsonPath('devices.1.summary.inactive_seconds', 10);
    }
}
