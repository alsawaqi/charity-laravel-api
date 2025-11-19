<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('device_apps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                  ->constrained('devices')
                  ->cascadeOnDelete();

            $table->foreignId('app_id')
                  ->constrained('apps')
                  ->cascadeOnDelete();

            // Optional extra info per device/app pair
            $table->string('installed_version')->nullable();  // e.g. "1.0.3"
            $table->boolean('is_primary')->default(false);    // main app on this device?

            $table->timestamps();

            // prevent duplicate rows: same app on same device twice
            $table->unique(['device_id', 'app_id'], 'device_app_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_apps');
    }
};
