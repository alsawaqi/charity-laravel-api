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
              Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            // Basic organization info
            $table->string('name');                     // e.g. "Charity Association Oman"
            $table->string('trade_name')->nullable();   // if they have a trade/commercial name
            $table->string('cr_number')->nullable();    // Commercial Registration number
            $table->string('tax_number')->nullable();   // VAT / tax number if applicable

            // Contact info
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Location hierarchy (NOT charity_locations_id – this is the "main office")
            $table->foreignId('country_id')
                  ->nullable()
                  ->constrained('countries')
                  ->nullOnDelete();

            $table->foreignId('region_id')
                  ->nullable()
                  ->constrained('regions')
                  ->nullOnDelete();

            $table->foreignId('city_id')
                  ->nullable()
                  ->constrained('cities')
                  ->nullOnDelete();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code')->nullable();

            // Bank link (to banks table)
            $table->foreignId('bank_id')
                  ->nullable()
                  ->constrained('banks')
                  ->nullOnDelete();

            // Bank account details for this organization
            $table->string('bank_account_name')->nullable();
            $table->string('iban')->nullable();
            $table->string('account_number')->nullable();
            $table->string('swift_code')->nullable(); // override if needed per account

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['name', 'country_id'], 'organizations_unique_name_per_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
