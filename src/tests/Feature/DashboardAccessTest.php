<?php

namespace Tests\Feature;

use App\Models\DashboardPermission;
use App\Models\DashboardRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_dashboard_access_cannot_log_in_to_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'no-access@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response
            ->assertStatus(403)
            ->assertJson([
                'message' => 'You are not allowed to access the admin dashboard.',
            ]);
    }

    public function test_owner_role_user_can_log_in_and_receives_dashboard_access_payload(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('password123'),
        ]);

        $ownerRole = DashboardRole::query()->where('slug', 'owner')->firstOrFail();
        $user->dashboardRoles()->attach($ownerRole->id);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.dashboard_access.has_access', true)
            ->assertJsonPath('user.dashboard_access.full_access', true)
            ->assertJsonPath('user.dashboard_roles.0.slug', 'owner');

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotEmpty($response->json('user.dashboard_permissions'));
    }

    public function test_dashboard_access_endpoint_requires_manage_permission(): void
    {
        $user = User::factory()->create();
        $overviewPermission = DashboardPermission::query()
            ->where('key', 'dashboard.overview.view')
            ->firstOrFail();

        $user->dashboardDirectPermissions()->attach($overviewPermission->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard-access/users')
            ->assertStatus(403)
            ->assertJson([
                'message' => 'You do not have permission to access this resource.',
            ]);
    }

    public function test_access_manager_can_create_dashboard_user(): void
    {
        $manager = User::factory()->create();
        $managerRole = DashboardRole::query()->create([
            'name' => 'Access Manager',
            'slug' => 'access-manager',
            'description' => 'Manages dashboard user access.',
            'grants_all' => false,
            'is_system' => false,
        ]);

        $managePermission = DashboardPermission::query()
            ->where('key', 'dashboard.access.manage')
            ->firstOrFail();

        $managerRole->permissions()->attach($managePermission->id);
        $manager->dashboardRoles()->attach($managerRole->id);

        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/dashboard-access/users', [
            'name' => 'Dashboard Operator',
            'email' => 'operator@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'dashboard_role_ids' => [$managerRole->id],
            'dashboard_permission_ids' => [],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'operator@example.com')
            ->assertJsonPath('data.dashboard_access.has_access', true);

        $createdUser = User::query()->where('email', 'operator@example.com')->first();

        $this->assertNotNull($createdUser);
        $this->assertDatabaseHas('dashboard_role_user', [
            'dashboard_role_id' => $managerRole->id,
            'user_id' => $createdUser->id,
        ]);
    }
}
