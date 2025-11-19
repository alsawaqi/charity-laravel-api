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
               Schema::create('charity_locations', function (Blueprint $table) {
            $table->id();

            // Basic charity info
            $table->string('name');                         // Charity branch name or location name
            $table->string('phone', 30)->nullable();        // Main phone number
            $table->string('email')->nullable();            // Optional email

            // Person responsible at that location
            $table->string('contact_person_name')->nullable();
            $table->string('contact_person_phone', 30)->nullable();
            $table->string('contact_person_email')->nullable();

            // Address details
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();

            // Location dropdowns (FKs to your existing tables)
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

            // Optional extra fields
            $table->string('postal_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            // If you want to avoid duplicate names per city:
            // $table->unique(['city_id', 'name'], 'charity_locations_unique_name_per_city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charity_locations');
    }
};
