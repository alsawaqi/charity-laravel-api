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
         Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');                // Oman, United Arab Emirates, United States
            $table->char('iso_code', 2)->unique(); // OM, AE, US
            $table->string('phone_code')->nullable(); // +968, +971, +1
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
