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
        Schema::table('devices', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('companies_id')->nullable()->after('id');
            $table->foreign('companies_id')->references('id')->on('companies')->onDelete('set null');

            $table->unsignedBigInteger('main_location_id')->nullable()->after('companies_id');
            $table->foreign('main_location_id')->references('id')->on('main_locations')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            //
        });
    }
};
