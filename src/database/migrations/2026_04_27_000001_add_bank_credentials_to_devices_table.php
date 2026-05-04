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
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'device_code')) {
                $table->string('device_code')->nullable()->after('terminal_id');
            }

            if (!Schema::hasColumn('devices', 'bank_username')) {
                $table->string('bank_username')->nullable()->after('device_code');
            }

            if (!Schema::hasColumn('devices', 'bank_password')) {
                $table->text('bank_password')->nullable()->after('bank_username');
            }
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS devices_device_code_unique ON devices (device_code)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS devices_device_code_unique');

        $columnsToDrop = array_values(array_filter([
            Schema::hasColumn('devices', 'device_code') ? 'device_code' : null,
            Schema::hasColumn('devices', 'bank_username') ? 'bank_username' : null,
            Schema::hasColumn('devices', 'bank_password') ? 'bank_password' : null,
        ]));

        if (empty($columnsToDrop)) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) use ($columnsToDrop) {
            $table->dropColumn($columnsToDrop);
        });
    }
};
