<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CharityMcpServer;
use App\Mcp\Tools\CharityOverviewTool;
use App\Models\CharityTransactions;
use App\Models\Devices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CharityMcpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_charity_overview_tool_returns_structured_totals_for_date_range(): void
    {
        $device = $this->createDevice();

        CharityTransactions::query()->create([
            'device_id' => $device->id,
            'country_id' => $device->country_id,
            'total_amount' => 10.500,
            'status' => 'success',
            'created_at' => '2026-05-01 09:00:00',
            'updated_at' => '2026-05-01 09:00:00',
        ]);

        CharityTransactions::query()->create([
            'device_id' => $device->id,
            'country_id' => $device->country_id,
            'total_amount' => 4.250,
            'status' => 'failed',
            'created_at' => '2026-05-01 10:00:00',
            'updated_at' => '2026-05-01 10:00:00',
        ]);

        CharityTransactions::query()->create([
            'device_id' => $device->id,
            'country_id' => $device->country_id,
            'total_amount' => 99.000,
            'status' => 'success',
            'created_at' => '2026-04-30 23:59:59',
            'updated_at' => '2026-04-30 23:59:59',
        ]);

        $response = CharityMcpServer::tool(CharityOverviewTool::class, [
            'from' => '2026-05-01',
            'to' => '2026-05-01',
        ]);

        $response
            ->assertOk()
            ->assertName('charity-overview')
            ->assertStructuredContent(fn ($json) => $json
                ->where('range.from', '2026-05-01')
                ->where('range.to', '2026-05-01')
                ->where('totals.transactions_count', 2)
                ->where('totals.success_count', 1)
                ->where('totals.success_amount', 10.5)
                ->where('totals.failed_count', 1)
                ->where('totals.failed_amount', 4.25)
                ->where('devices.active_count', 1)
                ->where('devices.total_count', 1)
                ->whereType('generated_at', 'string')
            );
    }

    private function createDevice(): Devices
    {
        $brandId = DB::table('device_brands')->insertGetId([
            'name' => 'Test Brand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modelId = DB::table('device_models')->insertGetId([
            'device_brand_id' => $brandId,
            'name' => 'Test Model',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $countryId = DB::table('countries')->insertGetId([
            'name' => 'Oman',
            'iso_code' => 'OM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Devices::query()->create([
            'device_brand_id' => $brandId,
            'device_model_id' => $modelId,
            'country_id' => $countryId,
            'kiosk_id' => 'KIOSK-001',
            'status' => 'active',
        ]);
    }
}
