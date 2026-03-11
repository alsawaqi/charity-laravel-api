<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class ScalefusionService
{
    /**
     * ✅ Best performance:
     * Build a single "summary map" from /api/v3/devices.json (list) and cache it.
     * This avoids calling /devices/{id}.json for every device.
     *
     * Key: (string) device.id  => picked fields
     */
    public function getDevicesSummaryMapCached(int $ttlSeconds = 120, int $maxPages = 25): array
    {
        $keyFresh = 'sf:v3:devices_summary_map';
        $keyStale = 'sf:v3:devices_summary_map_stale';

        // If we have fresh cache, return it immediately
        $fresh = Cache::get($keyFresh);
        if (is_array($fresh) && !empty($fresh)) {
            return $fresh;
        }

        // Try to rebuild; if throttled/fails, fall back to stale
        try {
            $map = $this->buildDevicesSummaryMap($maxPages);

            if (!empty($map)) {
                Cache::put($keyFresh, $map, now()->addSeconds($ttlSeconds));
                Cache::put($keyStale, $map, now()->addHours(12)); // safe fallback
                return $map;
            }
        } catch (\Throwable $e) {
            Log::warning('Scalefusion summary map build failed', ['error' => $e->getMessage()]);
        }

        $stale = Cache::get($keyStale);
        return is_array($stale) ? $stale : [];
    }

    /**
     * Existing API used in other pages.
     * We now fulfill it from the global summary map (fast),
     * and only fall back to scanning pages if something is missing.
     */
    public function findDevicesByIds(array $ids): array
    {
        $wanted = collect($ids)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->unique()
            ->values();

        if ($wanted->isEmpty()) return [];

        $summary = $this->getDevicesSummaryMapCached();

        $found = [];
        foreach ($wanted as $id) {
            if (isset($summary[$id])) {
                $found[$id] = $summary[$id];
            }
        }

        // if all found, done
        if (count($found) >= $wanted->count()) {
            return $found;
        }

        // fallback: scan pages a bit (rare)
        $missing = $wanted->filter(fn($id) => !isset($found[$id]))->values();
        $cursor = null;
        $guard = 0;

        while ($guard < 10 && $missing->isNotEmpty()) {
            $guard++;
            $json = $this->requestDevicesPage($cursor, 200);

            foreach (($json['devices'] ?? []) as $row) {
                $sfId = (string) data_get($row, 'device.id');
                if ($sfId !== '' && $missing->contains($sfId)) {
                    $found[$sfId] = $this->pickImportantFields($row);
                }
            }

            $cursor = $json['next_cursor'] ?? null;
            if (!$cursor) break;
        }

        return $found;
    }

    /**
     * Build full summary map by paging /devices.json.
     * Very few calls when total devices ~224 (your case).
     */
    protected function buildDevicesSummaryMap(int $maxPages = 25): array
    {
        $map = [];

        $cursor = null;
        $guard = 0;

        while ($guard < $maxPages) {
            $guard++;

            $json = $this->requestDevicesPage($cursor, 200);
            if (empty($json)) break;

            foreach (($json['devices'] ?? []) as $row) {
                $sfId = (string) data_get($row, 'device.id');
                if ($sfId !== '') {
                    $map[$sfId] = $this->pickImportantFields($row);
                }
            }

            $cursor = $json['next_cursor'] ?? null;
            if (!$cursor) break;
        }

        return $map;
    }

    /**
     * Request a v3 devices list page with backoff for 429.
     */
    protected function requestDevicesPage($cursor = null, int $perPage = 200): array
    {
        $token = config('services.scalefusion.token');
        $base  = rtrim(config('services.scalefusion.base_v3'), '/');

        if (!$token || !$base) return [];

        $params = [];
        if (!empty($cursor)) $params['cursor'] = $cursor;
        if ($perPage > 0) $params['per_page'] = $perPage; // harmless if API ignores it

        $tries = 3;
        $sleepUs = 350000; // 350ms, exponential

        try {
            for ($i = 1; $i <= $tries; $i++) {
                $res = Http::timeout(12)
                    ->withHeaders([
                        'Accept'        => 'application/json',
                        'Authorization' => 'Token ' . $token,
                    ])
                    ->get($base . '/devices.json', $params);

                if ($res->status() === 429) {
                    // Respect Retry-After if present
                    $retryAfter = (int) ($res->header('Retry-After') ?? 0);
                    usleep($retryAfter > 0 ? $retryAfter * 1000000 : $sleepUs);
                    $sleepUs *= 2;
                    continue;
                }

                if (!$res->ok()) return [];
                return $res->json();
            }
        } catch (ConnectionException $e) {
            Log::warning('Scalefusion unreachable', [
                'url' => $base . '/devices.json',
                'cursor' => $cursor,
                'error' => $e->getMessage(),
            ]);
            return [];
        } catch (\Throwable $e) {
            Log::warning('Scalefusion error', ['error' => $e->getMessage()]);
            return [];
        }

        return [];
    }

    protected function pickImportantFields(array $row): array
    {
        $d = data_get($row, 'device', []);

        return [
            'id'               => data_get($d, 'id'),
            'name'             => data_get($d, 'name'),

            'battery_status'   => data_get($d, 'battery_status'),
            'battery_charging' => data_get($d, 'battery_charging'),

            'connection_state'  => data_get($d, 'connection_state'),
            'connection_status' => data_get($d, 'connection_status'),

            'device_status'    => data_get($d, 'device_status'),
            'locked'           => data_get($d, 'locked'),

            'last_connected_at'=> data_get($d, 'last_connected_at'),
            'last_seen_on'     => data_get($d, 'last_seen_on'),

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