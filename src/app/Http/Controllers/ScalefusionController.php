<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use App\Services\ScalefusionService;
 
use Illuminate\Http\Client\Pool;

class ScalefusionController extends Controller
{
    private function token(): string
    {
        return (string) config('services.scalefusion.token');
    }

    private function v3(string $path): string
    {
        return rtrim(config('services.scalefusion.base_v3'), '/') . $path;
    }

    private function v1(string $path): string
    {
        return rtrim(config('services.scalefusion.base_v1'), '/') . $path;
    }

    private function client()
    {
        return Http::timeout(12)
            ->retry(2, 250)
            ->withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'Token ' . $this->token(),
            ]);
    }

    public function devices()
    {
        try {
            $res = $this->client()->get($this->v3('/devices.json'));
            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function device(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
        ]);

        try {
            $res = $this->client()->get($this->v3('/devices/' . $data['device_id'] . '.json'));
            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function reboot(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
        ]);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put($this->v1('/devices/' . $data['device_id'] . '/reboot.json'), []);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    public function alarm(Request $request)
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
        ]);

        try {
            $res = $this->client()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->v1('/devices/' . $data['device_id'] . '/send_alarm.json'), []);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/scalefusion/devices/lock
     * body: { "device_ids": [1,2,3] }
     */
    public function lock(Request $request)
    {
        $data = $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer',
        ]);

        // Scalefusion expects formData device_ids[]
        $payload = [];
        foreach ($data['device_ids'] as $i => $id) {
            $payload["device_ids[$i]"] = $id; // sends device_ids[]=... style
        }

        try {
            $res = $this->client()
                ->asForm()
                ->post($this->v1('/devices/lock.json'), $payload);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/scalefusion/devices/unlock
     * body: { "device_ids": [1,2,3] }
     */
    public function unlock(Request $request)
    {
        $data = $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer',
        ]);

        $payload = [];
        foreach ($data['device_ids'] as $i => $id) {
            $payload["device_ids[$i]"] = $id;
        }

        try {
            $res = $this->client()
                ->asForm()
                ->post($this->v1('/devices/unlock.json'), $payload);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'Scalefusion unreachable', 'error' => $e->getMessage()], 503);
        }
    }


    public function locationGeofence(Request $request, ScalefusionService $sf)
    {
        $data = $request->validate([
            'cursor' => 'nullable|integer',
            'only'   => 'nullable|string', // e.g. "online" or "online,active"
            'hide_no_location' => 'nullable|boolean',
        ]);
    
        $cursor = $data['cursor'] ?? null;
        $only = strtolower(trim((string)($data['only'] ?? '')));
        $onlySet = collect(explode(',', $only))
            ->map(fn($s) => strtolower(trim($s)))
            ->filter()
            ->unique();
    
        $hideNoLocation = filter_var($request->query('hide_no_location', false), FILTER_VALIDATE_BOOL);
    
        // 1) Fetch ONE page only (cursor pagination)
        $params = [];
        if (!empty($cursor)) $params['cursor'] = $cursor;
    
        $res = $this->client()->get($this->v1('/devices/location_geofence.json'), $params);
    
        // Handle 429 safely (don’t throw RequestException)
        if ($res->status() === 429) {
            return response()->json([
                'success' => false,
                'message' => 'Scalefusion throttled (429). Try again in a moment.',
            ], 429);
        }
    
        if (!$res->ok()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch location geofence from Scalefusion.',
                'status'  => $res->status(),
                'raw'     => $res->json(),
            ], 502);
        }
    
        $geo = $res->json();
        $geoDevices = $geo['devices'] ?? [];
    
        // 2) Get cached summary map from v3/devices.json (few calls, cached)
        $summaryMap = $sf->getDevicesSummaryMapCached(120);
    
        // 3) Merge + server filter "only"
        $out = [];
        $counts = [
            'total' => 0,
            'online' => 0,
            'offline' => 0,
            'active' => 0,
            'inactive' => 0,
            'locked' => 0,
            'charging' => 0,
            'no_location' => 0,
        ];
    
        foreach ($geoDevices as $d) {
            $id = (string)($d['device_id'] ?? '');
            if ($id === '') continue;
    
            $loc = $d['location'] ?? [];
            $lat = $loc['latitude'] ?? null;
            $lng = $loc['longitude'] ?? null;
    
            if ($hideNoLocation && (empty($lat) || empty($lng))) continue;
    
            $det = $summaryMap[$id] ?? [];
    
            $cs = strtolower((string)($det['connection_status'] ?? ''));
            $st = strtolower((string)($det['connection_state'] ?? ''));
    
            // server filtering:
            $ok = true;
    
            if ($onlySet->contains('online'))   $ok = $ok && ($cs === 'online');
            if ($onlySet->contains('offline'))  $ok = $ok && ($cs === 'offline');
    
            if ($onlySet->contains('active'))   $ok = $ok && ($st === 'active');
            if ($onlySet->contains('inactive')) $ok = $ok && ($st === 'inactive');
    
            if ($onlySet->contains('locked'))   $ok = $ok && !empty($det['locked']);
            if ($onlySet->contains('unlocked')) $ok = $ok && empty($det['locked']);
    
            if ($onlySet->contains('charging')) $ok = $ok && !empty($det['battery_charging']);
    
            if (!$ok) continue;
    
            // counts for returned page
            $counts['total']++;
            if ($cs === 'online') $counts['online']++;
            if ($cs === 'offline') $counts['offline']++;
            if ($st === 'active') $counts['active']++;
            if ($st === 'inactive') $counts['inactive']++;
            if (!empty($det['locked'])) $counts['locked']++;
            if (!empty($det['battery_charging'])) $counts['charging']++;
            if (empty($lat) || empty($lng)) $counts['no_location']++;
    
            $out[] = [
                'device_id' => (int)$id,
                'name'      => $d['name'] ?? ($det['name'] ?? null),
                'imei_no'   => $d['imei_no'] ?? null,
                'serial_no' => $d['serial_no'] ?? null,
                'location' => [
                    'lat'        => $lat,
                    'lng'        => $lng,
                    'address'    => $loc['address'] ?? null,
                    'date_time'  => $loc['date_time'] ?? null,
                    'created_at' => $loc['created_at_tz'] ?? null,
                ],
                'connection_state'  => $det['connection_state'] ?? null,
                'connection_status' => $det['connection_status'] ?? null,
                'battery_status'    => $det['battery_status'] ?? null,
                'battery_charging'  => $det['battery_charging'] ?? null,
                'locked'            => $det['locked'] ?? null,
                'last_seen_on'      => $det['last_seen_on'] ?? null,
                'last_connected_at' => $det['last_connected_at'] ?? null,
            ];
        }
    
        return response()->json([
            'success' => true,
            'total_count'    => $geo['total_count'] ?? null,
            'current_cursor' => $geo['current_cursor'] ?? $cursor,
            'next_cursor'    => $geo['next_cursor'] ?? null,
            'counts'         => $counts,
            'devices'        => $out,
        ]);
    }

 
}