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
          Schema::create('device_models', function (Blueprint $table) {
            $table->id();

            // FK -> device_brands.id
            $table->foreignId('device_brand_id')
                  ->constrained('device_brands')
                  ->cascadeOnDelete();

            $table->string('name');                 // e.g. "Galaxy Tab A7"
            $table->string('model_number')->nullable(); // e.g. "SM-T500"
            $table->string('device_type')->nullable();  // e.g. "tablet", "phone", "pos"
            $table->string('os')->nullable();       // e.g. "android", "ios", "windows"
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // prevent duplicate model name per brand
            $table->unique(['device_brand_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_models');
    }
};
