<?php

namespace Tests\Feature\Broadcasting;

use App\Events\CharityTransactionCreated;
use App\Models\CharityTransactions;
use App\Models\Devices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CharityTransactionBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_donation_broadcasts_transaction_created_event(): void
    {
        $device = $this->createDonationDevice();

        Event::fake([CharityTransactionCreated::class]);

        $this->postJson('/api/donations', [
            'id' => $device->kiosk_id,
            'amount' => 12.345,
            'receipt' => [
                'reason' => 'SUCCESS',
            ],
        ])->assertCreated();

        $transaction = CharityTransactions::query()->firstOrFail();

        Event::assertDispatched(
            CharityTransactionCreated::class,
            fn (CharityTransactionCreated $event): bool => $event->transaction->is($transaction)
        );
        Event::assertDispatchedTimes(CharityTransactionCreated::class, 1);
    }

    public function test_creating_any_charity_transaction_broadcasts_transaction_created_event(): void
    {
        $device = $this->createDonationDevice();

        Event::fake([CharityTransactionCreated::class]);

        $transaction = CharityTransactions::query()->create([
            'device_id' => $device->id,
            'commission_profile_id' => $device->commission_profile_id,
            'total_amount' => 4.250,
            'bank_transaction_id' => $device->bank_id,
            'status' => 'fail',
            'country_id' => $device->country_id,
            'region_id' => $device->region_id,
            'city_id' => $device->city_id,
            'charity_location_id' => $device->charity_location_id,
            'district_id' => $device->district_id,
            'company_id' => $device->companies_id,
            'main_location_id' => $device->main_location_id,
            'organization_id' => optional($device->charityLocation)->organization_id,
            'latitude' => 0,
            'longitude' => 0,
            'terminal_id' => $device->terminal_id,
        ]);

        Event::assertDispatched(
            CharityTransactionCreated::class,
            fn (CharityTransactionCreated $event): bool => $event->transaction->is($transaction)
                && $event->transaction->status === 'fail'
        );
        Event::assertDispatchedTimes(CharityTransactionCreated::class, 1);
    }

    private function createDonationDevice(): Devices
    {
        $brandId = DB::table('device_brands')->insertGetId([
            'name' => 'Broadcast Brand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modelId = DB::table('device_models')->insertGetId([
            'device_brand_id' => $brandId,
            'name' => 'Broadcast Model',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $countryId = DB::table('countries')->insertGetId([
            'name' => 'Oman',
            'iso_code' => 'OM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commissionProfileId = DB::table('commission_profiles')->insertGetId([
            'name' => 'Broadcast Profile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Devices::query()->create([
            'device_brand_id' => $brandId,
            'device_model_id' => $modelId,
            'country_id' => $countryId,
            'commission_profile_id' => $commissionProfileId,
            'kiosk_id' => 'KIOSK-WS-001',
            'status' => 'active',
        ]);
    }
}
