<?php

namespace App\Events;

use App\Models\CharityTransactions;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CharityTransactionCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public CharityTransactions $transaction)
    {
        //
    }

    public function broadcastOn(): Channel
    {
        return new Channel('charity.transactions');
    }

    public function broadcastAs(): string
    {
        return 'created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->transaction->id,
            'total_amount' => (float) $this->transaction->total_amount,
            'status' => $this->transaction->status,
            'device_id' => $this->transaction->device_id,
            'bank_id' => $this->transaction->bank_transaction_id,
            'organization_id' => $this->transaction->organization_id,
            'company_id' => $this->transaction->company_id,
            'main_location_id' => $this->transaction->main_location_id,
            'charity_location_id' => $this->transaction->charity_location_id,
            'created_at' => optional($this->transaction->created_at)->toIso8601String(),
        ];
    }
}
