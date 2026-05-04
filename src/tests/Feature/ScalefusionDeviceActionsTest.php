<?php

namespace Tests\Feature;

use App\Models\DashboardPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScalefusionDeviceActionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsLiveDevicesUser(): void
    {
        $user = User::factory()->create();
        $permission = DashboardPermission::query()
            ->where('key', 'dashboard.devices.live.view')
            ->firstOrFail();

        $user->dashboardDirectPermissions()->attach($permission->id);

        Sanctum::actingAs($user);
    }

    public function test_user_can_broadcast_message_to_selected_device(): void
    {
        Http::fake([
            'https://api.scalefusion.com/api/v1/devices/broadcast_message.json' => Http::response([
                'success' => true,
                'message' => 'Broadcast queued',
            ], 200),
        ]);

        $this->actingAsLiveDevicesUser();

        $response = $this->postJson('/api/scalefusion/device/broadcast-message', [
            'device_id' => 7104376,
            'sender_name' => 'Charity Admin',
            'message_body' => 'Please check this kiosk.',
            'keep_ringing' => true,
            'show_as_dialog' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Broadcast queued');

        Http::assertSent(function ($request) {
            parse_str($request->body(), $body);

            return $request->method() === 'POST'
                && $request->url() === 'https://api.scalefusion.com/api/v1/devices/broadcast_message.json'
                && str_contains($request->body(), 'device_ids%5B%5D=7104376')
                && ($body['device_ids'] ?? []) === ['7104376']
                && ($body['sender_name'] ?? null) === 'Charity Admin'
                && ($body['message_body'] ?? null) === 'Please check this kiosk.'
                && ($body['keep_ringing'] ?? null) === 'true'
                && ($body['show_as_dialog'] ?? null) === 'true';
        });
    }

    public function test_user_can_perform_action_on_selected_device(): void
    {
        Http::fake([
            'https://api.scalefusion.com/api/v1/devices/actions.json*' => Http::response([
                'success' => true,
                'message' => 'Action queued',
            ], 200),
        ]);

        $this->actingAsLiveDevicesUser();

        $response = $this->postJson('/api/scalefusion/device/action', [
            'device_id' => 7104376,
            'action_type' => 'mark_as_lost',
            'lost_mode_message' => 'This kiosk is managed by Charity Portal.',
            'lost_mode_footnote' => 'Please contact support.',
            'lost_mode_phone' => '+968 9999 0000',
            'wipe_sd_card' => false,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Action queued');

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            parse_str($request->body(), $body);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://api.scalefusion.com/api/v1/devices/actions.json')
                && ($query['device_ids'] ?? []) === ['7104376']
                && ($body['action_type'] ?? null) === 'mark_as_lost'
                && ($body['lost_mode_message'] ?? null) === 'This kiosk is managed by Charity Portal.'
                && ($body['lost_mode_footnote'] ?? null) === 'Please contact support.'
                && ($body['lost_mode_phone'] ?? null) === '+968 9999 0000'
                && ($body['wipe_sd_card'] ?? null) === 'false'
                && ! array_key_exists('remote_wipe_type', $body)
                && ! array_key_exists('factory_reset_mode', $body);
        });
    }

    public function test_user_can_clear_app_data_on_selected_device(): void
    {
        Http::fake([
            'https://api.scalefusion.com/api/v1/devices/clear_app_data.json' => Http::response([
                'success' => true,
                'message' => 'Clear app data queued',
            ], 200),
        ]);

        $this->actingAsLiveDevicesUser();

        $response = $this->postJson('/api/scalefusion/device/clear-app-data', [
            'device_id' => 7104376,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Clear app data queued');

        Http::assertSent(function ($request) {
            parse_str($request->body(), $body);

            return $request->method() === 'POST'
                && $request->url() === 'https://api.scalefusion.com/api/v1/devices/clear_app_data.json'
                && str_contains($request->body(), 'device_ids%5B%5D=7104376')
                && ($body['device_ids'] ?? []) === ['7104376'];
        });
    }
}
