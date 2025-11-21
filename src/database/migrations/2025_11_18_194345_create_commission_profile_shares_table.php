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
     Schema::create('commission_profile_shares', function (Blueprint $table) {
            $table->id();

            // Link to commission profile (template)
            $table->foreignId('commission_profile_id')
                  ->constrained('commission_profiles')
                  ->cascadeOnDelete();

            // Label/user-friendly name for the share (A, B, "Charity", "Admin", etc.)
            $table->string('label'); // what you show in the UI

            // Percentage of the total commission (e.g. 40, 30, 10, 20)
            // use decimal if you want 12.5 etc.
   

            // Optional: if later you link to a specific entity (user, charity, etc.)
            // $table->string('target_type')->nullable(); // e.g. 'user', 'charity'
            // $table->unsignedBigInteger('target_id')->nullable();

            $table->unsignedInteger('sort_order')->default(0); // for ordering in UI

            $table->timestamps();

            // Avoid duplicate labels within the same profile
           
 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_profile_shares');
    }
};
