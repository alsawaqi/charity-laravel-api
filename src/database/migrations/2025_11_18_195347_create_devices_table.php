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
   Schema::create('devices', function (Blueprint $table) {
            $table->id();

            // Brand & model
            $table->foreignId('device_brand_id')
                  ->constrained('device_brands')
                  ->cascadeOnDelete();

            $table->foreignId('device_model_id')
                  ->constrained('device_models')
                  ->cascadeOnDelete();


             $table->foreignId('bank_id')
                  ->nullable()
                  ->constrained('banks')
                  ->nullOnDelete();
      

            // Optional extra model/serial identifier printed on the device
            $table->string('model_number')->nullable();      // e.g. "SM-T500", internal label

            // Location hierarchy (for filtering)
            $table->foreignId('country_id')
                  ->constrained('countries')
                  ->cascadeOnDelete();

            $table->foreignId('region_id')
                  ->nullable()
                  ->constrained('regions')
                  ->nullOnDelete();

            $table->foreignId('city_id')
                  ->nullable()
                  ->constrained('cities')
                  ->nullOnDelete();

            // Where the device physically sits (charity branch / mosque / mall, etc.)
            $table->foreignId('charity_location_id')
                  ->nullable()
                  ->constrained('charity_locations')
                  ->nullOnDelete();

            // Commission template used for this device
            $table->foreignId('commission_profile_id')
                  ->nullable()
                  ->constrained('commission_profiles')
                  ->nullOnDelete();

            // Kiosk & login info
            $table->string('kiosk_id')->nullable();          // external kiosk ID (Scalefusion, etc.)
            $table->string('login_generated_token', 100)
                  ->nullable()
                  ->unique();                                // token used when device logs in

            // Status / meta
            $table->string('status', 30)->default('active'); // active, disabled, maintenance...
            $table->date('installed_at')->nullable();        // when device was installed

            $table->timestamps();

            // Useful indexes for filtering
            $table->index(['country_id', 'region_id', 'city_id'], 'devices_location_index');
            $table->index(['charity_location_id'], 'devices_charity_location_index');
            $table->index(['commission_profile_id'], 'devices_commission_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
