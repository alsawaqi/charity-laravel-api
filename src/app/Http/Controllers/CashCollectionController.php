<?php

namespace App\Http\Controllers;

use App\Models\CashCollection;
use App\Models\CharityLocation;
use App\Models\Country;
use App\Models\MainLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CashCollectionController extends Controller
{
    public function filters(Request $request)
    {
       $mainLocations = MainLocation::query()
          ->select('id', 'name', 'organization_id', 'country_id', 'region_id', 'district_id', 'city_id')
            ->with([
                'charityLocations' => function ($q) {
                    $q->select('id', 'main_location_id', 'name', 'organization_id', 'country_id', 'region_id', 'district_id', 'city_id')
                        ->orderBy('name');
                },
            ])
            ->orderBy('name')
            ->get();

        $collectors = User::query()
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        $countries = Country::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'main_locations' => $mainLocations,
                'collectors' => $collectors,
                'countries' => $countries,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 200));

        $page = $this->buildQuery($request)
            ->paginate($perPage)
            ->appends($request->query());

        $page->getCollection()->transform(fn (CashCollection $row) => $this->serializeRow($row));

        return response()->json($page);
    }

    public function show(CashCollection $cashCollection)
    {
        $cashCollection->load($this->relations());

        return response()->json($this->serializeRow($cashCollection));
    }

    public function export(Request $request)
    {
        $filename = 'cash_collections_export_' . now()->format('Ymd_His') . '.csv';

        $query = $this->buildQuery($request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                        'ID',
                        'Collected At',
                        'Amount',
                        'Organization',
                        'Collector',
                        'Collector Email',
                        'Witness Name',
                        'Has Witness Signature',
                        'Country',
                        'Region',
                        'District',
                        'City',
                        'Main Location',
                        'Charity Location',
                        'Collector Signature URL',
                        'Witness Signature URL',
                    ]);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    $row = $this->serializeRow($row);

                    fputcsv($out, [
                        $row->id,
                        optional($row->collected_at)?->format('Y-m-d H:i:s') ?? '',
                        $row->amount,
                        $row->organization?->name ?? '',
                        $row->collector?->name ?? '',
                        $row->collector?->email ?? '',
                        $row->witness_name ?? '',
                        $row->has_witness_signature ? 'yes' : 'no',
                        $row->country?->name ?? '',
                        $row->region?->name ?? '',
                        $row->district?->name ?? '',
                        $row->city?->name ?? '',
                        $row->mainLocation?->name ?? '',
                        $row->charityLocation?->name ?? '',
                        $row->collector_signature_url ?? '',
                        $row->witness_signature_url ?? '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(Request $request)
    {
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $validated = $request->validate([
                'main_location_id'    => ['required', 'exists:main_locations,id'],
                'charity_location_id' => ['required', 'exists:charity_locations,id'],
                'amount'              => ['required', 'numeric', 'min:0'],
                'collector_signature' => ['required', 'string'],
                'witness_signature'   => ['required', 'string'],
                'witness_name'        => ['nullable', 'string', 'max:255'],
                'collected_at'        => ['nullable', 'date'],
            ]);

            try {

        
            $mainLocation = MainLocation::query()
                    ->select('id', 'organization_id')
                    ->findOrFail((int) $validated['main_location_id']);

                $charityLocation = CharityLocation::query()
                    ->select('id', 'main_location_id', 'country_id', 'region_id', 'district_id', 'city_id')
                    ->findOrFail((int) $validated['charity_location_id']);

                if ((int) $charityLocation->main_location_id !== (int) $mainLocation->id) {
                    return response()->json([
                        'message' => 'Selected charity location does not belong to the selected main location.',
                    ], 422);
                }

            $collectorPath = $this->storeSignature($validated['collector_signature'], 'collector');
            $witnessPath = $this->storeSignature($validated['witness_signature'], 'witness');

            $row = CashCollection::create([
                'organization_id' => $mainLocation->organization_id,
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

            $row->load($this->relations());

            return response()->json($this->serializeRow($row), 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create cash collection.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildQuery(Request $request)
    {
        $query = CashCollection::query()
            ->with($this->relations())
            ->orderByDesc('collected_at')
            ->orderByDesc('id');

        if ($request->filled('main_location_id')) {
            $query->where('main_location_id', (int) $request->input('main_location_id'));
        }

        if ($request->filled('charity_location_id')) {
            $query->where('charity_location_id', (int) $request->input('charity_location_id'));
        }

        if ($request->filled('organization_id')) {
        $query->where('organization_id', (int) $request->input('organization_id'));
         }

        if ($request->filled('collected_by_user_id')) {
            $query->where('collected_by_user_id', (int) $request->input('collected_by_user_id'));
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', (int) $request->input('country_id'));
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', (int) $request->input('region_id'));
        }

        if ($request->filled('district_id')) {
            $query->where('district_id', (int) $request->input('district_id'));
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', (int) $request->input('city_id'));
        }

        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', (float) $request->input('min_amount'));
        }

        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', (float) $request->input('max_amount'));
        }

        if ($request->filled('witness_name')) {
            $term = trim((string) $request->input('witness_name'));
            $query->where('witness_name', 'like', "%{$term}%");
        }

        if ($request->filled('from')) {
            $query->where('collected_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('collected_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        $hasWitnessSignature = $request->query('has_witness_signature');
        if ($hasWitnessSignature !== null && $hasWitnessSignature !== '') {
            $flag = filter_var($hasWitnessSignature, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($flag === true) {
                $query->whereNotNull('witness_signature_path')
                    ->where('witness_signature_path', '!=', '');
            } elseif ($flag === false) {
                $query->where(function ($q) {
                    $q->whereNull('witness_signature_path')
                        ->orWhere('witness_signature_path', '');
                });
            }
        }

        return $query;
    }

    private function relations(): array
    {
        return [
            'organization:id,name',
            'country:id,name',
            'region:id,name',
            'district:id,name',
            'city:id,name',
            'mainLocation:id,name',
            'charityLocation:id,name',
            'collector:id,name,email',
        ];
    }

    private function serializeRow(CashCollection $row): CashCollection
    {
        $row->collector_signature_url = $this->publicUrl($row->collector_signature_path);
        $row->witness_signature_url = $this->publicUrl($row->witness_signature_path);
        $row->has_witness_signature = filled($row->witness_signature_path);

        return $row;
    }

    private function storeSignature(string $dataUrl, string $type): string
    {
        $base64 = $dataUrl;
        if (str_starts_with($dataUrl, 'data:image')) {
            $parts = explode(',', $dataUrl, 2);
            $base64 = $parts[1] ?? '';
        }

        $binary = base64_decode($base64, true);
        if ($binary === false) {
            abort(422, 'Invalid signature image.');
        }

        $date = Carbon::now()->format('Y-m-d');
        $name = Str::uuid()->toString() . "_{$type}.png";
        $path = "cash-collections/signatures/{$date}/{$name}";

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    private function publicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}