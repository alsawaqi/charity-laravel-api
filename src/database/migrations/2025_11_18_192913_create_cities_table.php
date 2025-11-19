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
         Schema::create('cities', function (Blueprint $table) {
            $table->id();

            // Each city belongs to a region (state / emirate / governorate)
            $table->foreignId('region_id')
                  ->constrained('regions')
                  ->cascadeOnDelete();

            $table->string('name');              // Bawshar, Ruwi, Los Angeles
            $table->string('postal_code')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['region_id', 'name'], 'cities_unique_name_per_region');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
