<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('dashboard_permissions')->updateOrInsert(
            ['key' => 'dashboard.device-availability.view'],
            [
                'label' => 'Device Availability',
                'group' => 'Status',
                'path' => '/dashboard/device-availability',
                'description' => 'View Scalefusion device availability reports linked to local devices.',
                'sort_order' => 75,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('dashboard_permissions')
            ->where('key', 'dashboard.device-availability.view')
            ->delete();
    }
};
