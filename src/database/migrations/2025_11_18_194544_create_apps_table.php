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
       Schema::create('apps', function (Blueprint $table) {
            $table->id();

            // Display name of the app
            $table->string('name');                 // e.g. "Charity Donation Kiosk"

            // Android (or iOS/web) identifier - reverse domain style
            $table->string('package_name')->unique(); 
            // e.g. "com.charity.kiosk" or "com.mycompany.charityapp"

            // android / ios / web / other
            $table->string('platform', 20)->default('android');

            // Optional: current version string
            $table->string('current_version')->nullable(); // e.g. "1.0.3"

            // Optional: store links
            $table->string('store_url')->nullable();   // Google Play / App Store URL

            // Optional: anything you want to show in admin UI
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
