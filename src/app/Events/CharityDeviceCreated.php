<?php

namespace App\Events;

use App\Models\Devices;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CharityDeviceCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Devices $device)
    {
        //
    }

    public function broadcastOn(): Channel
    {
        return new Channel('charity.devices');
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
            'id' => $this->device->id,
            'total_devices' => Devices::query()->count(),
            'kiosk_id' => $this->device->kiosk_id,
            'terminal_id' => $this->device->terminal_id,
            'status' => $this->device->status,
            'device_brand_id' => $this->device->device_brand_id,
            'device_model_id' => $this->device->device_model_id,
            'bank_id' => $this->device->bank_id,
            'company_id' => $this->device->companies_id,
            'main_location_id' => $this->device->main_location_id,
            'charity_location_id' => $this->device->charity_location_id,
            'created_at' => optional($this->device->created_at)->toIso8601String(),
        ];
    }
}
