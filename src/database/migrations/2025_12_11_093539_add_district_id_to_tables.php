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
       Schema::table('cities', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
        });


        Schema::table('main_locations', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
        });

        Schema::table('charity_locations', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            //
        });
    }
};
