<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
           Schema::table('charity_transactions', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('charity_location_id')
                ->constrained('organizations')
                ->nullOnDelete();
        });

        // Backfill existing rows based on charity_location -> organization_id
       
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
