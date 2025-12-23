<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ScalefusionService
{
    public function findDevicesByIds(array $ids): array
    {
        // normalize ids to strings (your kiosk_id is string)
        $wanted = collect($ids)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->unique()
            ->values();

        if ($wanted->isEmpty()) return [];

        // cache per-request set (fast + avoids hammering scalefusion on every page refresh)
        $cacheKey = 'sf_map:' . md5($wanted->implode(','));

        return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($wanted) {
            $found = [];

            $cursor = null;
            $guard = 0;

            // loop pages until we found all needed ids OR no more pages
            while ($guard < 25) {
                $guard++;

                $json = $this->requestDevicesPage($cursor);

                foreach (($json['devices'] ?? []) as $row) {
                    $sfId = (string) data_get($row, 'device.id');
                    if ($sfId !== '' && $wanted->contains($sfId)) {
                        $found[$sfId] = $this->pickImportantFields($row);
                    }
                }

                // stop early if we found everything
                if (count($found) >= $wanted->count()) break;

                $cursor = $json['next_cursor'] ?? null;
                if (!$cursor) break;
            }

            return $found;
        });
    }

    protected function requestDevicesPage($cursor = null): array
    {
        $token = config('services.scalefusion.token');
        $base  = rtrim(config('services.scalefusion.base_url'), '/');

        if (!$token) return [];

        $params = [];
        if (!empty($cursor)) {
            // Scalefusion uses cursor-style pagination (you get next_cursor in response)
            $params['cursor'] = $cursor;
        }

        $res = Http::timeout(12)
            ->retry(2, 200)
            ->withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'Token ' . $token,
            ])
            ->get($base . '/devices.json', $params);

        // if scalefusion fails, we just return empty and your main API still works
        if (!$res->ok()) return [];

        return $res->json();
    }

    protected function pickImportantFields(array $row): array
    {
        $d = data_get($row, 'device', []);

        return [
            'id'               => data_get($d, 'id'),
            'name'             => data_get($d, 'name'),

            // ✅ the important ones you mentioned
            'battery_status'   => data_get($d, 'battery_status'),
            'battery_charging' => data_get($d, 'battery_charging'),

            'connection_state'  => data_get($d, 'connection_state'),   // e.g. Inactive
            'connection_status' => data_get($d, 'connection_status'),  // e.g. Offline

            'device_status'    => data_get($d, 'device_status'),       // e.g. Locked
            'locked'           => data_get($d, 'locked'),

            'last_connected_at'=> data_get($d, 'last_connected_at'),
            'last_seen_on'     => data_get($d, 'last_seen_on'),

            // optional helpful bits (small)
            'ip_address'       => data_get($d, 'ip_address'),
            'public_ip'        => data_get($d, 'public_ip'),

            'location' => [
                'lat'       => data_get($d, 'location.lat'),
                'lng'       => data_get($d, 'location.lng'),
                'address'   => data_get($d, 'location.address'),
                'date_time' => data_get($d, 'location.date_time'),
            ],
        ];
    }
}
