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
          Schema::create('regions', function (Blueprint $table) {
            $table->id();

            // Each region belongs to a country
            $table->foreignId('country_id')
                  ->constrained('countries')
                  ->cascadeOnDelete();

            $table->string('name');          // Muscat, Dubai, California
            $table->string('type', 50)
                  ->nullable();             // governorate, emirate, state, province...
            $table->string('code')->nullable(); // internal code if you need
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // avoid duplicates within same country
            $table->unique(['country_id', 'name'], 'regions_unique_name_per_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
