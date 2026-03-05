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
        Schema::create('cash_collections', function (Blueprint $table) {
            $table->id();

            // snapshot of location hierarchy (copied from charity_locations)
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();

            $table->foreignId('main_location_id')->constrained('main_locations')->cascadeOnDelete();
            $table->foreignId('charity_location_id')->constrained('charity_locations')->cascadeOnDelete();

            $table->decimal('amount', 12, 3);

            // collector (logged-in user)
            $table->foreignId('collected_by_user_id')->constrained('users')->cascadeOnDelete();

            // witness
            $table->string('witness_name')->nullable();

            // signatures saved as images in storage/app/public
            $table->string('collector_signature_path');
            $table->string('witness_signature_path');

            $table->timestamp('collected_at')->useCurrent();
            $table->timestamps();

            $table->index(['main_location_id', 'charity_location_id', 'collected_at'], 'cash_collections_loc_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_collections');
    }
};
