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
        Schema::table('charity_transactions', function (Blueprint $table) {
            //
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
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charity_transactions', function (Blueprint $table) {
            //
        });
    }
};
