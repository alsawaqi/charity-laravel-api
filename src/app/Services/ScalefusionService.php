<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScalefusionService
{
    private bool $hasSummaryMapMemo = false;

    private array $summaryMapMemo = [];

    /**
     * Build a single summary map from Scalefusion's v3 devices list
     * and cache it briefly so changing telemetry still feels live.
     */
    public function getDevicesSummaryMapCached(int $ttlSeconds = 120, int $maxPages = 25): array
    {
        $ttlSeconds = (int) config('services.scalefusion.summary_ttl_seconds', $ttlSeconds);
        $staleTtlSeconds = (int) config('services.scalefusion.stale_ttl_seconds', 900);
        $lockSeconds = (int) config('services.scalefusion.cache_lock_seconds', 20);
        $maxPages = (int) config('services.scalefusion.max_pages', $maxPages);

        if ($this->hasSummaryMapMemo) {
            return $this->summaryMapMemo;
        }

        $store = $this->cacheStore();
        $keyFresh = 'sf:v3:devices_summary_map';
        $keyStale = 'sf:v3:devices_summary_map_stale';
        $keyLock = 'sf:v3:devices_summary_map_refresh_lock';

        $fresh = $store->get($keyFresh);
        if (is_array($fresh) && ! empty($fresh)) {
            return $this->rememberSummaryMap($fresh);
        }

        $stale = $store->get($keyStale);
        $stale = is_array($stale) ? $stale : [];

        $lock = $this->cacheLock($keyLock, $lockSeconds);

        if (! empty($stale)) {
            if ($lock && $lock->get()) {
                try {
                    $refreshed = $this->refreshSummaryMap(
                        $store,
                        $keyFresh,
                        $keyStale,
                        $ttlSeconds,
                        $staleTtlSeconds,
                        $maxPages
                    );

                    if (! empty($refreshed)) {
                        return $refreshed;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Scalefusion stale refresh failed', ['error' => $e->getMessage()]);
                } finally {
                    optional($lock)->release();
                }
            }

            return $this->rememberSummaryMap($stale);
        }

        if ($lock) {
            try {
                $result = $lock->block(3, function () use (
                    $store,
                    $keyFresh,
                    $keyStale,
                    $ttlSeconds,
                    $staleTtlSeconds,
                    $maxPages
                ) {
                    $fresh = $store->get($keyFresh);
                    if (is_array($fresh) && ! empty($fresh)) {
                        return $fresh;
                    }

                    return $this->refreshSummaryMap(
                        $store,
                        $keyFresh,
                        $keyStale,
                        $ttlSeconds,
                        $staleTtlSeconds,
                        $maxPages
                    );
                });

                return $this->rememberSummaryMap(is_array($result) ? $result : []);
            } catch (\Throwable $e) {
                Log::warning('Scalefusion cache lock wait failed', ['error' => $e->getMessage()]);
            }
        }

        try {
            $map = $this->refreshSummaryMap(
                $store,
                $keyFresh,
                $keyStale,
                $ttlSeconds,
                $staleTtlSeconds,
                $maxPages
            );

            if (! empty($map)) {
                return $map;
            }
        } catch (\Throwable $e) {
            Log::warning('Scalefusion summary map build failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Existing API used in other pages.
     * We fulfill it from the cached global summary map
     * and only scan pages when something is missing.
     */
    public function findDevicesByIds(array $ids): array
    {
        $wanted = collect($ids)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        $summary = $this->getDevicesSummaryMapCached();

        $found = [];
        foreach ($wanted as $id) {
            if (isset($summary[$id])) {
                $found[$id] = $summary[$id];
            }
        }

        if (count($found) >= $wanted->count()) {
            return $found;
        }

        $missing = $wanted->filter(fn ($id) => ! isset($found[$id]))->values();
        $cursor = null;
        $guard = 0;
        $perPage = (int) config('services.scalefusion.per_page', 200);

        while ($guard < 10 && $missing->isNotEmpty()) {
            $guard++;
            $json = $this->requestDevicesPage($cursor, $perPage);

            foreach (($json['devices'] ?? []) as $row) {
                $sfId = (string) data_get($row, 'device.id');
                if ($sfId !== '' && $missing->contains($sfId)) {
                    $found[$sfId] = $this->pickImportantFields($row);
                }
            }

            $cursor = $json['next_cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        return $found;
    }

    /**
     * Build a full summary map by paging /devices.json.
     */
    protected function buildDevicesSummaryMap(int $maxPages = 25): array
    {
        $map = [];
        $cursor = null;
        $guard = 0;
        $perPage = (int) config('services.scalefusion.per_page', 200);

        while ($guard < $maxPages) {
            $guard++;

            $json = $this->requestDevicesPage($cursor, $perPage);
            if (empty($json)) {
                break;
            }

            foreach (($json['devices'] ?? []) as $row) {
                $sfId = (string) data_get($row, 'device.id');
                if ($sfId !== '') {
                    $map[$sfId] = $this->pickImportantFields($row);
                }
            }

            $cursor = $json['next_cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        return $map;
    }

    /**
     * Request a v3 devices list page with backoff for 429.
     */
    protected function requestDevicesPage($cursor = null, int $perPage = 200): array
    {
        $token = config('services.scalefusion.token');
        $base = rtrim(config('services.scalefusion.base_v3'), '/');
        $timeoutSeconds = (int) config('services.scalefusion.http_timeout_seconds', 8);
        $tries = (int) config('services.scalefusion.http_retry_attempts', 3);

        if (! $token || ! $base) {
            return [];
        }

        $params = [];
        if (! empty($cursor)) {
            $params['cursor'] = $cursor;
        }
        if ($perPage > 0) {
            $params['per_page'] = $perPage;
        }

        $sleepUs = 350000;

        try {
            for ($i = 1; $i <= $tries; $i++) {
                $response = Http::timeout($timeoutSeconds)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Authorization' => 'Token '.$token,
                    ])
                    ->get($base.'/devices.json', $params);

                if ($response->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 0);
                    usleep($retryAfter > 0 ? $retryAfter * 1000000 : $sleepUs);
                    $sleepUs *= 2;

                    continue;
                }

                if (! $response->ok()) {
                    return [];
                }

                return $response->json();
            }
        } catch (ConnectionException $e) {
            Log::warning('Scalefusion unreachable', [
                'url' => $base.'/devices.json',
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
        $device = data_get($row, 'device', []);

        return [
            'id' => data_get($device, 'id'),
            'name' => data_get($device, 'name'),
            'battery_status' => data_get($device, 'battery_status'),
            'battery_charging' => data_get($device, 'battery_charging'),
            'connection_state' => data_get($device, 'connection_state'),
            'connection_status' => data_get($device, 'connection_status'),
            'device_status' => data_get($device, 'device_status'),
            'locked' => data_get($device, 'locked'),
            'last_connected_at' => data_get($device, 'last_connected_at'),
            'last_seen_on' => data_get($device, 'last_seen_on'),
            'ip_address' => data_get($device, 'ip_address'),
            'public_ip' => data_get($device, 'public_ip'),
            'location' => [
                'lat' => data_get($device, 'location.lat'),
                'lng' => data_get($device, 'location.lng'),
                'address' => data_get($device, 'location.address'),
                'date_time' => data_get($device, 'location.date_time'),
            ],
        ];
    }

    protected function refreshSummaryMap($store, string $keyFresh, string $keyStale, int $ttlSeconds, int $staleTtlSeconds, int $maxPages): array
    {
        $map = $this->buildDevicesSummaryMap($maxPages);

        if (! empty($map)) {
            $store->put($keyFresh, $map, now()->addSeconds($ttlSeconds));
            $store->put($keyStale, $map, now()->addSeconds($staleTtlSeconds));

            return $this->rememberSummaryMap($map);
        }

        return [];
    }

    protected function rememberSummaryMap(array $map): array
    {
        $this->hasSummaryMapMemo = true;
        $this->summaryMapMemo = $map;

        return $map;
    }

    protected function cacheStore(): Repository
    {
        $configuredStore = config('services.scalefusion.cache_store', config('cache.default'));

        try {
            return Cache::store($configuredStore);
        } catch (\Throwable $e) {
            Log::warning('Scalefusion cache store fallback engaged', [
                'store' => $configuredStore,
                'error' => $e->getMessage(),
            ]);

            return Cache::store(config('cache.default'));
        }
    }

    protected function cacheLock(string $key, int $seconds): ?Lock
    {
        $repository = $this->cacheStore();
        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            Log::warning('Scalefusion cache store does not support locks', [
                'driver' => $store::class,
            ]);

            return null;
        }

        try {
            return $store->lock($key, $seconds);
        } catch (\Throwable $e) {
            Log::warning('Scalefusion cache lock failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
