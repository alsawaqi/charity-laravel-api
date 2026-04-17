<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dashboard_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('group')->nullable();
            $table->string('path')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('dashboard_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('grants_all')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('dashboard_permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_role_id')
                ->constrained('dashboard_roles')
                ->cascadeOnDelete();
            $table->foreignId('dashboard_permission_id')
                ->constrained('dashboard_permissions')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['dashboard_role_id', 'dashboard_permission_id'],
                'dashboard_permission_role_unique'
            );
        });

        Schema::create('dashboard_role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_role_id')
                ->constrained('dashboard_roles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['dashboard_role_id', 'user_id'], 'dashboard_role_user_unique');
        });

        Schema::create('dashboard_permission_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_permission_id')
                ->constrained('dashboard_permissions')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['dashboard_permission_id', 'user_id'], 'dashboard_permission_user_unique');
        });

        $now = now();

        $permissions = [
            [
                'key' => 'dashboard.overview.view',
                'label' => 'Dashboard Overview',
                'group' => 'Overview',
                'path' => '/dashboard',
                'description' => 'View the main overview dashboard.',
                'sort_order' => 10,
            ],
            [
                'key' => 'dashboard.devices.live.view',
                'label' => 'Live Devices',
                'group' => 'Application',
                'path' => '/dashboard/devices',
                'description' => 'View live device status and Scalefusion actions.',
                'sort_order' => 20,
            ],
            [
                'key' => 'dashboard.charity.view',
                'label' => 'Charity',
                'group' => 'Application',
                'path' => '/dashboard/charity',
                'description' => 'View charity transaction summaries.',
                'sort_order' => 30,
            ],
            [
                'key' => 'dashboard.transactions.view',
                'label' => 'Transactions',
                'group' => 'Application',
                'path' => '/dashboard/transactions',
                'description' => 'View filtered transaction history.',
                'sort_order' => 40,
            ],
            [
                'key' => 'dashboard.bank-reconciliation.manage',
                'label' => 'Bank Reconciliation',
                'group' => 'Application',
                'path' => '/dashboard/bank-reconciliation',
                'description' => 'Preview and commit bank reconciliation files.',
                'sort_order' => 50,
            ],
            [
                'key' => 'dashboard.search.view',
                'label' => 'Charity Status',
                'group' => 'Status',
                'path' => '/dashboard/search',
                'description' => 'View global charity status search.',
                'sort_order' => 60,
            ],
            [
                'key' => 'dashboard.device-geo-location.view',
                'label' => 'Device Geo Map',
                'group' => 'Status',
                'path' => '/dashboard/device-geo-location',
                'description' => 'View device locations on the geo map.',
                'sort_order' => 70,
            ],
            [
                'key' => 'dashboard.status.devices.view',
                'label' => 'Status By Device',
                'group' => 'Status',
                'path' => '/dashboard/status',
                'description' => 'View device-level status analytics.',
                'sort_order' => 80,
            ],
            [
                'key' => 'dashboard.status.locations.view',
                'label' => 'Status By Location',
                'group' => 'Status',
                'path' => '/dashboard/locations',
                'description' => 'View location-level status analytics.',
                'sort_order' => 90,
            ],
            [
                'key' => 'dashboard.device-locations.view',
                'label' => 'Search Device Location',
                'group' => 'Status',
                'path' => '/dashboard/locations/device-locations',
                'description' => 'Search device locations.',
                'sort_order' => 100,
            ],
            [
                'key' => 'dashboard.ai.view',
                'label' => 'AI Analysis',
                'group' => 'Status',
                'path' => '/dashboard/ai',
                'description' => 'View AI analysis and AI overview pages.',
                'sort_order' => 110,
            ],
            [
                'key' => 'dashboard.activities.manage',
                'label' => 'Activities',
                'group' => 'Administration',
                'path' => '/dashboard/activities',
                'description' => 'Manage company activities.',
                'sort_order' => 120,
            ],
            [
                'key' => 'dashboard.companies.manage',
                'label' => 'Companies',
                'group' => 'Administration',
                'path' => '/dashboard/companies',
                'description' => 'Manage companies.',
                'sort_order' => 130,
            ],
            [
                'key' => 'dashboard.countries.manage',
                'label' => 'Countries',
                'group' => 'Administration',
                'path' => '/dashboard/countries',
                'description' => 'Manage countries.',
                'sort_order' => 140,
            ],
            [
                'key' => 'dashboard.regions.manage',
                'label' => 'Regions',
                'group' => 'Administration',
                'path' => '/dashboard/regions',
                'description' => 'Manage regions.',
                'sort_order' => 150,
            ],
            [
                'key' => 'dashboard.districts.manage',
                'label' => 'Districts',
                'group' => 'Administration',
                'path' => '/dashboard/districts',
                'description' => 'Manage districts.',
                'sort_order' => 160,
            ],
            [
                'key' => 'dashboard.cities.manage',
                'label' => 'Cities',
                'group' => 'Administration',
                'path' => '/dashboard/cities',
                'description' => 'Manage cities.',
                'sort_order' => 170,
            ],
            [
                'key' => 'dashboard.organizations.manage',
                'label' => 'Organizations',
                'group' => 'Administration',
                'path' => '/dashboard/organization',
                'description' => 'Manage organizations and organizer logins.',
                'sort_order' => 180,
            ],
            [
                'key' => 'dashboard.main-locations.manage',
                'label' => 'Main Locations',
                'group' => 'Administration',
                'path' => '/dashboard/main-locations',
                'description' => 'Manage main locations.',
                'sort_order' => 190,
            ],
            [
                'key' => 'dashboard.charity-locations.manage',
                'label' => 'Charity Locations',
                'group' => 'Administration',
                'path' => '/dashboard/charity-location',
                'description' => 'Manage charity locations.',
                'sort_order' => 200,
            ],
            [
                'key' => 'dashboard.cash-collections.manage',
                'label' => 'Cash Collections',
                'group' => 'Administration',
                'path' => '/dashboard/cash-collection',
                'description' => 'Manage cash collection entries.',
                'sort_order' => 210,
            ],
            [
                'key' => 'dashboard.device-brands.manage',
                'label' => 'Device Brands',
                'group' => 'Administration',
                'path' => '/dashboard/device-brands',
                'description' => 'Manage device brands.',
                'sort_order' => 220,
            ],
            [
                'key' => 'dashboard.device-models.manage',
                'label' => 'Device Models',
                'group' => 'Administration',
                'path' => '/dashboard/device-models',
                'description' => 'Manage device models.',
                'sort_order' => 230,
            ],
            [
                'key' => 'dashboard.banks.manage',
                'label' => 'Banks',
                'group' => 'Administration',
                'path' => '/dashboard/banks',
                'description' => 'Manage banks.',
                'sort_order' => 240,
            ],
            [
                'key' => 'dashboard.commission-profiles.manage',
                'label' => 'Commission Profiles',
                'group' => 'Administration',
                'path' => '/dashboard/commission-profiles',
                'description' => 'Manage commission profiles.',
                'sort_order' => 250,
            ],
            [
                'key' => 'dashboard.commission-share-report.view',
                'label' => 'Commission Share Report',
                'group' => 'Administration',
                'path' => '/dashboard/commission-share-report',
                'description' => 'View commission share reports.',
                'sort_order' => 260,
            ],
            [
                'key' => 'dashboard.devices.manage',
                'label' => 'Devices',
                'group' => 'Administration',
                'path' => '/dashboard/device',
                'description' => 'Manage devices.',
                'sort_order' => 270,
            ],
            [
                'key' => 'dashboard.access.manage',
                'label' => 'Users & Access',
                'group' => 'Administration',
                'path' => '/dashboard/admin-users',
                'description' => 'Manage dashboard users, roles, and permissions.',
                'sort_order' => 280,
            ],
        ];

        DB::table('dashboard_permissions')->insert(
            array_map(
                fn (array $permission) => [
                    ...$permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $permissions
            )
        );

        DB::table('dashboard_roles')->insert([
            'name' => 'Owner',
            'slug' => 'owner',
            'description' => 'Full access to every dashboard page and setting.',
            'grants_all' => true,
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ownerRoleId = DB::table('dashboard_roles')->where('slug', 'owner')->value('id');
        $ownerUserId = DB::table('users')->min('id');

        if ($ownerRoleId && $ownerUserId) {
            DB::table('dashboard_role_user')->insert([
                'dashboard_role_id' => $ownerRoleId,
                'user_id' => $ownerUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_permission_user');
        Schema::dropIfExists('dashboard_role_user');
        Schema::dropIfExists('dashboard_permission_role');
        Schema::dropIfExists('dashboard_roles');
        Schema::dropIfExists('dashboard_permissions');
    }
};
