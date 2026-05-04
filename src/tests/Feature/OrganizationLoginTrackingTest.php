<?php

namespace Tests\Feature;

use App\Models\DashboardPermission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationLoginTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_show_returns_current_login_status_and_history(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('organization_login_logs'));

        $organization = Organization::query()->create([
            'name' => 'Oman Charity',
            'is_active' => true,
        ]);

        $organizer = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'organizer@example.com',
        ]);

        DB::table('organization_login_logs')->insert([
            [
                'user_id' => $organizer->id,
                'organization_id' => $organization->id,
                'session_id' => 'old-session',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Feature Test',
                'login_at' => '2026-05-03 08:00:00',
                'logout_at' => '2026-05-03 09:00:00',
                'created_at' => '2026-05-03 08:00:00',
                'updated_at' => '2026-05-03 09:00:00',
            ],
            [
                'user_id' => $organizer->id,
                'organization_id' => $organization->id,
                'session_id' => 'current-session',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Feature Test',
                'login_at' => '2026-05-04 10:00:00',
                'logout_at' => null,
                'created_at' => '2026-05-04 10:00:00',
                'updated_at' => '2026-05-04 10:00:00',
            ],
        ]);

        $admin = User::factory()->create();
        $permission = DashboardPermission::query()
            ->where('key', 'dashboard.organizations.manage')
            ->firstOrFail();

        $admin->dashboardDirectPermissions()->attach($permission->id);
        Sanctum::actingAs($admin);

        $this->getJson('/api/organizations/'.$organization->id)
            ->assertOk()
            ->assertJsonPath('organizer_login.status', 'online')
            ->assertJsonPath('organizer_login.latest_login_at', '2026-05-04T10:00:00+04:00')
            ->assertJsonPath('organizer_login.latest_logout_at', null)
            ->assertJsonCount(2, 'login_history')
            ->assertJsonPath('login_history.0.session_id', 'current-session')
            ->assertJsonPath('login_history.1.logout_at', '2026-05-03T09:00:00+04:00');
    }
}
