<?php

namespace App\Http\Controllers;

use App\Models\CashCollection;
use App\Models\CharityLocation;
use App\Models\MainLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CashCollectionController extends Controller
{
    // Main Location -> Charity Locations (for dropdowns)
    public function filters(Request $request)
    {
        $mainLocations = MainLocation::query()
            ->select('id', 'name', 'country_id', 'region_id', 'district_id', 'city_id')
            ->with(['charityLocations:id,main_location_id,name,country_id,region_id,district_id,city_id'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $mainLocations]);
    }

    // History list
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 200));

        $query = CashCollection::query()
            ->with([
                'mainLocation:id,name',
                'charityLocation:id,name',
                'collector:id,name,email',
            ])
            ->orderByDesc('collected_at');

        if ($request->filled('main_location_id')) {
            $query->where('main_location_id', (int) $request->input('main_location_id'));
        }

        if ($request->filled('charity_location_id')) {
            $query->where('charity_location_id', (int) $request->input('charity_location_id'));
        }

        if ($request->filled('from')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $query->where('collected_at', '>=', $from);
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->input('to'))->endOfDay();
            $query->where('collected_at', '<=', $to);
        }

        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function (CashCollection $row) {
            $row->collector_signature_url = $this->publicUrl($row->collector_signature_path);
            $row->witness_signature_url   = $this->publicUrl($row->witness_signature_path);
            return $row;
        });

        return response()->json($page);
    }

    public function show(CashCollection $cashCollection)
    {
        $cashCollection->load([
            'mainLocation:id,name',
            'charityLocation:id,name',
            'collector:id,name,email',
        ]);

        $cashCollection->collector_signature_url = $this->publicUrl($cashCollection->collector_signature_path);
        $cashCollection->witness_signature_url   = $this->publicUrl($cashCollection->witness_signature_path);

        return response()->json($cashCollection);
    }

    // Create record + store signature images
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $validated = $request->validate([
            'main_location_id'     => ['required', 'exists:main_locations,id'],
            'charity_location_id'  => ['required', 'exists:charity_locations,id'],
            'amount'               => ['required', 'numeric', 'min:0'],
            'collector_signature'  => ['required', 'string'],
            'witness_signature'    => ['required', 'string'],
            'witness_name'         => ['nullable', 'string', 'max:255'],
            'collected_at'         => ['nullable', 'date'],
        ]);
        try{
        $charityLocation = CharityLocation::query()
            ->select('id','main_location_id','country_id','region_id','district_id','city_id')
            ->findOrFail((int) $validated['charity_location_id']);

        // strict integrity: charity location must belong to selected main location
        if ((int) $charityLocation->main_location_id !== (int) $validated['main_location_id']) {
            return response()->json([
                'message' => 'Selected charity location does not belong to the selected main location.',
            ], 422);
        }

        $collectorPath = $this->storeSignature($validated['collector_signature'], 'collector');
        $witnessPath   = $this->storeSignature($validated['witness_signature'], 'witness');

      

        $row = CashCollection::create([
            // snapshot from charity_locations
            'country_id' => $charityLocation->country_id,
            'region_id' => $charityLocation->region_id,
            'district_id' => $charityLocation->district_id,
            'city_id' => $charityLocation->city_id,

            'main_location_id' => (int) $validated['main_location_id'],
            'charity_location_id' => (int) $validated['charity_location_id'],

            'amount' => $validated['amount'],
            'collected_by_user_id' => (int) $user->id,
            'witness_name' => $validated['witness_name'] ?? null,

            'collector_signature_path' => $collectorPath,
            'witness_signature_path' => $witnessPath,

            'collected_at' => isset($validated['collected_at'])
                ? Carbon::parse($validated['collected_at'])
                : Carbon::now(),
        ]);

        $row->load(['mainLocation:id,name','charityLocation:id,name','collector:id,name,email']);
        $row->collector_signature_url = $this->publicUrl($row->collector_signature_path);
        $row->witness_signature_url   = $this->publicUrl($row->witness_signature_path);

        return response()->json($row, 201);

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to create cash collection.',
            'error' => $e->getMessage(),
        ], 500);
    }
    }

    private function storeSignature(string $dataUrl, string $type): string
    {
        $base64 = $dataUrl;
        if (str_starts_with($dataUrl, 'data:image')) {
            $parts = explode(',', $dataUrl, 2);
            $base64 = $parts[1] ?? '';
        }

        $binary = base64_decode($base64, true);
        if ($binary === false) abort(422, 'Invalid signature image.');

        $date = Carbon::now()->format('Y-m-d');
        $name = Str::uuid()->toString() . "_{$type}.png";
        $path = "cash-collections/signatures/{$date}/{$name}";

        Storage::disk('public')->put($path, $binary);
        return $path;
    }

    private function publicUrl(?string $path): ?string
    {
        if (!$path) return null;
        // Usually returns "/storage/...." (or full URL if APP_URL is set)
        return Storage::disk('public')->url($path);
    }
}