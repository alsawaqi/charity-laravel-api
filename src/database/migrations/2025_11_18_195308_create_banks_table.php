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
        Schema::create('banks', function (Blueprint $table) {
              $table->id();

            $table->string('name');                // e.g. "Bank Muscat"
            $table->string('short_name')->nullable(); // e.g. "BMUSCAT"

            // Link to your countries table (optional but useful)
            $table->foreignId('country_id')
                  ->nullable()
                  ->constrained('countries')
                  ->nullOnDelete();

            // Banking details
            $table->string('swift_code')->nullable();      // e.g. "BMUSOMRX"
            $table->string('iban_example')->nullable();    // pattern or sample IBAN
            $table->string('branch_name')->nullable();     // main branch name if needed

            // Contact & meta
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['name', 'country_id'], 'banks_unique_name_per_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
