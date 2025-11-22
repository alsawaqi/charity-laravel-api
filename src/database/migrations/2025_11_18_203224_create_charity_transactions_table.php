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
        Schema::create('charity_transactions', function (Blueprint $table) {
            $table->id();

            // Device that generated this transaction
            $table->foreignId('device_id')
                ->constrained('devices')
                ->cascadeOnDelete();

            // Snapshot of commission profile at the time of transaction
            $table->foreignId('commission_profile_id')
                ->nullable()
                ->constrained('commission_profiles')
                ->nullOnDelete();

            // Total amount from the bank app (the full donation)
            $table->decimal('total_amount', 12, 3); // you said "double" but decimal is safer for money

            // Raw JSON response from bank app
            $table->jsonb('bank_response')->nullable(); // Postgres jsonb

            // Optional external identifiers


            $table->foreignId('bank_transaction_id')
                ->nullable()
                ->constrained('banks')
                ->nullOnDelete();

            $table->string('reference')->nullable();           // internal reference if you want

            // pending / success / failed / refunded / etc.
            $table->string('status', 30)->default('pending');

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['device_id', 'status'], 'charity_transactions_device_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charity_transactions');
    }
};
