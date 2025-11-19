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
        Schema::create('commission_profiles', function (Blueprint $table) {
            $table->id();

            $table->string('name');                 // e.g. "Masjid Box Split 40/30/30"
            $table->text('description')->nullable(); // optional notes
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_profiles');
    }
};
