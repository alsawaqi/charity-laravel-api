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
        Schema::create('charity_transaction_shares', function (Blueprint $table) {
               $table->id();

            // Link to the main transaction
            $table->foreignId('charity_transaction_id')
                  ->constrained('charity_transactions')
                  ->cascadeOnDelete();

            // Link to the original commission profile share definition
            $table->foreignId('commission_profile_share_id')
                  ->constrained('commission_profile_shares')
                  ->cascadeOnDelete();

            // Snapshot of which organization receives this share
          
 
            $table->decimal('amount', 12, 3);               // computed: total * percentage / 100

            $table->timestamps();

            // One row per share per transaction
            $table->unique(
                ['charity_transaction_id', 'commission_profile_share_id'],
                'charity_tx_share_unique'
            );

       

       
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charity_transaction_shares');
    }
};
