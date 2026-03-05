<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

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
}