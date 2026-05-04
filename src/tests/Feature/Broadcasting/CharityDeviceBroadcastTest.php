<?php

namespace Tests\Feature\Broadcasting;

use App\Events\CharityDeviceCreated;
use App\Models\DashboardRole;
use App\Models\Devices;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CharityDeviceBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_dashboard_device_broadcasts_device_created_event(): void
    {
        Sanctum::actingAs($this->createDashboardOwner());

        $fixture = $this->createDeviceFormFixture();

        Event::fake([CharityDeviceCreated::class]);

        $this->postJson('/api/devices', [
            'device_brand_id' => $fixture['device_brand_id'],
            'device_model_id' => $fixture['device_model_id'],
            'bank_id' => $fixture['bank_id'],
            'commission_profile_id' => $fixture['commission_profile_id'],
            'main_location_id' => $fixture['main_location_id'],
            'charity_location_id' => $fixture['charity_location_id'],
            'kiosk_id' => 'KIOSK-DEVICE-WS-001',
            'terminal_id' => 'TERM-DEVICE-WS-001',
            'status' => 'active',
        ])->assertCreated();

        $device = Devices::query()
            ->where('kiosk_id', 'KIOSK-DEVICE-WS-001')
            ->firstOrFail();

        Event::assertDispatched(
            CharityDeviceCreated::class,
            fn (CharityDeviceCreated $event): bool => $event->device->is($device)
                && $event->broadcastWith()['total_devices'] === 1
        );
    }

    private function createDashboardOwner(): User
    {
        $user = User::factory()->create();

        $ownerRole = DashboardRole::query()
            ->where('slug', 'owner')
            ->firstOrFail();

        $user->dashboardRoles()->attach($ownerRole->id);

        return $user;
    }

    /**
     * @return array<string, int>
     */
    private function createDeviceFormFixture(): array
    {
        $countryId = DB::table('countries')->insertGetId([
            'name' => 'Oman',
            'iso_code' => 'OM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Broadcast Company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mainLocationId = DB::table('main_locations')->insertGetId([
            'country_id' => $countryId,
            'company_id' => $companyId,
            'name' => 'Broadcast Main Location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $charityLocationId = DB::table('charity_locations')->insertGetId([
            'country_id' => $countryId,
            'main_location_id' => $mainLocationId,
            'name' => 'Broadcast Charity Location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceBrandId = DB::table('device_brands')->insertGetId([
            'name' => 'Broadcast Device Brand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceModelId = DB::table('device_models')->insertGetId([
            'device_brand_id' => $deviceBrandId,
            'name' => 'Broadcast Device Model',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bankId = DB::table('banks')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Broadcast Bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commissionProfileId = DB::table('commission_profiles')->insertGetId([
            'name' => 'Broadcast Commission Profile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'device_brand_id' => $deviceBrandId,
            'device_model_id' => $deviceModelId,
            'bank_id' => $bankId,
            'commission_profile_id' => $commissionProfileId,
            'main_location_id' => $mainLocationId,
            'charity_location_id' => $charityLocationId,
        ];
    }
}
