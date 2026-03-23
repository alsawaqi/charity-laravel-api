<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Banks;
use App\Models\Region;
use App\Models\Country;
use App\Models\Devices;
use App\Models\District;
use App\Models\DeviceBrand;
use App\Models\DeviceModel;
use App\Models\MainLocation;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\CharityLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\CharityTransactions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CharityStatsController extends Controller
{


private function paginatorMeta(LengthAwarePaginator $paginator): array
{
    return [
        'current_page' => $paginator->currentPage(),
        'last_page' => $paginator->lastPage(),
        'per_page' => $paginator->perPage(),
        'total' => $paginator->total(),
        'from' => $paginator->firstItem(),
        'to' => $paginator->lastItem(),
        'has_more_pages' => $paginator->hasMorePages(),
    ];
}

    private function successStatuses(): array
    {
        // supports both styles (in case DB has mixed old/new values)
        return ['success', 'successful'];
    }

    private function failedStatuses(): array
    {
        return ['fail', 'failed'];
    }

    private function resolveVisibleOrgIds(Request $request): array
    {
        $user = $request->user();
        if (!$user) return [];

        $orgId = $user->organization_id;

        // If user has no org: allow all (same behavior you used)
        if (!$orgId) {
            return Organization::pluck('id')->all();
        }

        $org = Organization::with('children')->find($orgId);
        if (!$org) {
            // fallback to only orgId (or empty)
            return [$orgId];
        }

        return $org->descendantsAndSelfIds();
    }

    /**
     * NEW: Same logic as your original topDevices(), but filtered by date range (+ orgs)
     */
    private function topDevicesForStatusRange(
    Carbon $start,
    Carbon $end,
    array $visibleOrgIds,
    ?int $organizationId = null,
    ?int $companyId = null,
    ?int $mainLocationId = null,
    ?int $charityLocationId = null
): array
    {
        $success = $this->successStatuses();

        $rows = DB::table('charity_transactions as ct')
            ->join('devices as d', 'ct.device_id', '=', 'd.id')
            ->leftJoin('device_models as dm', 'd.device_model_id', '=', 'dm.id')
            ->leftJoin('device_brands as db', 'd.device_brand_id', '=', 'db.id')
            ->whereBetween('ct.created_at', [$start, $end])
            ->whereIn('ct.status', $success)
            ->when(!empty($visibleOrgIds), fn($q) => $q->whereIn('ct.organization_id', $visibleOrgIds))
            ->when($organizationId, fn($q) => $q->where('ct.organization_id', $organizationId))
            ->when($companyId, fn($q) => $q->where('ct.company_id', $companyId))
            ->when($mainLocationId, fn($q) => $q->where('ct.main_location_id', $mainLocationId))
            ->when($charityLocationId, fn($q) => $q->where('ct.charity_location_id', $charityLocationId))
            ->selectRaw("
            d.device_brand_id,
            d.device_model_id,
            COALESCE(db.name, 'Unknown Brand') as brand_name,
            COALESCE(dm.name, 'Unknown Model') as model_name,
            SUM(ct.total_amount) as total_amount,
            COUNT(*) as tx_count
        ")
            ->groupBy('d.device_brand_id', 'd.device_model_id', 'db.name', 'dm.name')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(function ($row) use ($start, $end, $visibleOrgIds, $success) {

                // Same idea as your original: find top charity location for this (brand+model)
                $topLoc = DB::table('charity_transactions as ct')
                    ->join('devices as d', 'ct.device_id', '=', 'd.id')
                    ->whereBetween('ct.created_at', [$start, $end])
                    ->whereIn('ct.status', $success)
                    ->when(!empty($visibleOrgIds), fn($q) => $q->whereIn('ct.organization_id', $visibleOrgIds))
                    ->where('d.device_brand_id', $row->device_brand_id)
                    ->where('d.device_model_id', $row->device_model_id)
                    ->select('ct.charity_location_id', DB::raw('SUM(ct.total_amount) as total_amount'))
                    ->groupBy('ct.charity_location_id')
                    ->orderByDesc('total_amount')
                    ->first();

                $locationLabel = '—';

                if ($topLoc?->charity_location_id) {
                    $loc = CharityLocation::with('main_location')->find($topLoc->charity_location_id);
                    $main = $loc?->main_location?->name;
                    $sub  = $loc?->name;
                    $locationLabel = ($main && $sub) ? "{$main} - {$sub}" : ($sub ?? '—');
                }

                return [
                    'device_brand_id' => (int) $row->device_brand_id,
                    'device_model_id' => (int) $row->device_model_id,
                    'brand'           => (string) $row->brand_name,
                    'model'           => (string) $row->model_name,
                    'label'           => trim($row->brand_name . ' - ' . $row->model_name),
                    'location_label'  => $locationLabel,
                    'total_amount'    => (float) $row->total_amount,
                    'tx_count'        => (int) $row->tx_count,
                ];
            })
            ->values();

        return $rows->toArray();
    }

    /**
     * NEW: Same logic as your original topLocation(), but filtered by date range (+ orgs)
     */
    private function topLocationsForStatusRange(
                                Carbon $start,
                                Carbon $end,
                                array $visibleOrgIds,
                                ?int $organizationId = null,
                                ?int $companyId = null,
                                ?int $mainLocationId = null,
                                ?int $charityLocationId = null
                            ): array
    {
        $success = $this->successStatuses();

        $rows = CharityTransactions::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', $success)
            ->when(!empty($visibleOrgIds), fn($q) => $q->whereIn('organization_id', $visibleOrgIds))
            ->when($organizationId, fn($q) => $q->where('organization_id', $organizationId))
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->when($mainLocationId, fn($q) => $q->where('main_location_id', $mainLocationId))
            ->when($charityLocationId, fn($q) => $q->where('charity_location_id', $charityLocationId))
            ->select('charity_location_id', DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('charity_location_id')
            ->orderByDesc('total_amount')
            ->with(['charityLocation.main_location'])
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $main = $row->charityLocation?->main_location?->name;
                $sub  = $row->charityLocation?->name;

                $label = ($main && $sub)
                    ? ($main . ' - ' . $sub)
                    : ($sub ?? ('Location #' . $row->charity_location_id));

                return [
                    'charity_location_id' => $row->charity_location_id,
                    'label'               => $label,
                    'total_amount'        => (float) $row->total_amount,
                ];
            });

        return $rows->toArray();
    }



   public function index(Request $request)
{
    $range = $request->input('range', '7d');
    $from  = $request->input('from');
    $to    = $request->input('to');

    $end = Carbon::today()->endOfDay();

    switch ($range) {
        case '30d':
            $start = $end->copy()->subDays(29)->startOfDay();
            break;
        case '6m':
            $start = $end->copy()->subMonthsNoOverflow(6)->startOfDay();
            break;
        case 'custom':
            if (!$from || !$to) {
                return response()->json([
                    'message' => 'From and to dates are required for custom range',
                ], 422);
            }
            $start = Carbon::parse($from)->startOfDay();
            $end   = Carbon::parse($to)->endOfDay();
            break;
        case '7d':
        default:
            $start = $end->copy()->subDays(6)->startOfDay();
            break;
    }

    $organizationId = $request->integer('organization_id');
    $companyId = $request->integer('company_id');
    $mainLocationId = $request->integer('main_location_id');
    $charityLocationId = $request->integer('charity_location_id');

    if ($organizationId && $companyId) {
        return response()->json([
            'message' => 'Select either organization or company, not both.',
        ], 422);
    }

    $successPage = max(1, (int) $request->input('success_page', 1));
    $failedPage = max(1, (int) $request->input('failed_page', 1));

    $successPerPage = min(100, max(5, (int) $request->input('success_per_page', 10)));
    $failedPerPage = min(100, max(5, (int) $request->input('failed_per_page', 10)));

    $visibleOrgIds = $this->resolveVisibleOrgIds($request);

    $baseQuery = CharityTransactions::query()
        ->whereBetween('created_at', [$start, $end])
        ->when(!empty($visibleOrgIds), fn($q) => $q->whereIn('organization_id', $visibleOrgIds))
        ->when($organizationId, fn($q) => $q->where('organization_id', $organizationId))
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->when($mainLocationId, fn($q) => $q->where('main_location_id', $mainLocationId))
        ->when($charityLocationId, fn($q) => $q->where('charity_location_id', $charityLocationId));

    $successTotal = (float) (clone $baseQuery)
        ->whereIn('status', $this->successStatuses())
        ->sum('total_amount');

    $failedTotal = (float) (clone $baseQuery)
        ->whereIn('status', $this->failedStatuses())
        ->sum('total_amount');

    $bar = (clone $baseQuery)
        ->whereIn('status', $this->successStatuses())
        ->selectRaw('DATE(created_at) as date, SUM(total_amount) as total_amount')
        ->groupBy('date')
        ->orderBy('date')
        ->get();

    $topDevices = $this->topDevicesForStatusRange(
        $start,
        $end,
        $visibleOrgIds,
        $organizationId,
        $companyId,
        $mainLocationId,
        $charityLocationId
    );

    $topLocations = $this->topLocationsForStatusRange(
        $start,
        $end,
        $visibleOrgIds,
        $organizationId,
        $companyId,
        $mainLocationId,
        $charityLocationId
    );

    $successTransactions = (clone $baseQuery)
        ->whereIn('status', $this->successStatuses())
        ->with([
            'bank:id,name',
            'charityLocation:id,name,main_location_id',
            'mainLocation:id,name',
            'organization:id,name',
            'company:id,name',
        ])
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->paginate($successPerPage, ['*'], 'success_page', $successPage);

    $failedTransactions = (clone $baseQuery)
        ->whereIn('status', $this->failedStatuses())
        ->with([
            'bank:id,name',
            'charityLocation:id,name,main_location_id',
            'mainLocation:id,name',
            'organization:id,name',
            'company:id,name',
        ])
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->paginate($failedPerPage, ['*'], 'failed_page', $failedPage);

    $byHourRaw = (clone $baseQuery)
        ->whereIn('status', $this->successStatuses())
        ->selectRaw('
            ((EXTRACT(DOW FROM created_at)::int + 6) % 7) as weekday_index,
            EXTRACT(HOUR FROM created_at)::int as hour_of_day,
            SUM(total_amount) as total_amount
        ')
        ->groupBy('weekday_index', 'hour_of_day')
        ->orderBy('weekday_index')
        ->orderBy('hour_of_day')
        ->get();

    $weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    $matrix = [];
    foreach (range(0, 23) as $h) {
        $matrix[$h] = array_fill(0, 7, 0.0);
    }

    foreach ($byHourRaw as $row) {
        $w = (int) $row->weekday_index;
        $h = (int) $row->hour_of_day;

        if (isset($matrix[$h][$w])) {
            $matrix[$h][$w] = (float) $row->total_amount;
        }
    }

    $hourSeries = [];
    foreach (range(0, 23) as $h) {
        $hourSeries[] = [
            'name' => sprintf('%02d:00', $h),
            'data' => $matrix[$h],
        ];
    }

    return response()->json([
        'totals' => [
            'success' => $successTotal,
            'failed'  => $failedTotal,
        ],
        'bar' => [
            'categories' => $bar->pluck('date'),
            'data'       => $bar->pluck('total_amount')->map(fn($v) => (float) $v),
        ],
        'top_devices'   => $topDevices,
        'top_locations' => $topLocations,

        'success_transactions' => [
            'data' => $successTransactions->items(),
            'meta' => $this->paginatorMeta($successTransactions),
        ],

        'failed_transactions' => [
            'data' => $failedTransactions->items(),
            'meta' => $this->paginatorMeta($failedTransactions),
        ],

        'sales_by_hour' => [
            'categories' => $weekdayNames,
            'series'     => $hourSeries,
        ],
    ]);
}


    public function dailyTotals(): JsonResponse
    {
        // Postgres pivot-style sums per day
        $data = CharityTransactions::query()
            ->selectRaw("DATE(created_at) as date")
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN total_amount::numeric ELSE 0 END) as success_amount")
            ->selectRaw("SUM(CASE WHEN status = 'fail' THEN total_amount::numeric ELSE 0 END) as failed_amount")
            ->groupByRaw("DATE(created_at)")
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }


    public function totals(): JsonResponse
    {
        $successQuery = CharityTransactions::query()->where('status', 'success');

        $totalAmount = (clone $successQuery)->sum('total_amount');
        $totalCount  = (clone $successQuery)->count();

        $totalDevices     = Devices::count();
        $charityLocations = CharityLocation::count();

        return response()->json([
            'success' => true,
            'data'    => [
                // ✅ now success-only
                'total_amount'        => (float) $totalAmount,
                'total_transactions'  => (int) $totalCount,

                // unchanged "system totals"
                'total_devices'       => (int) $totalDevices,
                'total_locations'     => (int) $charityLocations,
            ],
        ]);
    }



    public function topDevices(): JsonResponse
    {
        try {
            // 1) Top (brand + model) by success total_amount
            $rows = DB::table('charity_transactions as ct')
                ->join('devices as d', 'ct.device_id', '=', 'd.id')
                ->leftJoin('device_models as dm', 'd.device_model_id', '=', 'dm.id')   // <-- adjust table name if different
                ->leftJoin('device_brands as db', 'd.device_brand_id', '=', 'db.id')  // <-- adjust table name if different
                ->where('ct.status', 'success')
                ->selectRaw('
                                    d.device_brand_id,
                                    d.device_model_id,
                                    COALESCE(db.name, \'Unknown Brand\') as brand_name,
                                    COALESCE(dm.name, \'Unknown Model\') as model_name,
                                    SUM(ct.total_amount) as total_amount,
                                    COUNT(*) as tx_count
                                    ')
                ->where('ct.status', 'success')
                ->groupBy('d.device_brand_id', 'd.device_model_id', 'db.name', 'dm.name')
                ->orderByDesc('total_amount')
                ->limit(5)
                ->get()
                ->map(function ($row) {

                    // 2) For this (brand+model), find top charity location (success-only)
                     $topLoc = DB::table('charity_transactions as ct')
    ->join('devices as d', 'ct.device_id', '=', 'd.id')
    ->whereBetween('ct.created_at', [$start, $end])
    ->whereIn('ct.status', $success)
    ->when(!empty($visibleOrgIds), fn($q) => $q->whereIn('ct.organization_id', $visibleOrgIds))
    ->when($organizationId, fn($q) => $q->where('ct.organization_id', $organizationId))
    ->when($companyId, fn($q) => $q->where('ct.company_id', $companyId))
    ->when($mainLocationId, fn($q) => $q->where('ct.main_location_id', $mainLocationId))
    ->when($charityLocationId, fn($q) => $q->where('ct.charity_location_id', $charityLocationId))
    ->where('d.device_brand_id', $row->device_brand_id)
    ->where('d.device_model_id', $row->device_model_id)
                        ->select('ct.charity_location_id', DB::raw('SUM(ct.total_amount) as total_amount'))
                        ->groupBy('ct.charity_location_id')
                        ->orderByDesc('total_amount')
                        ->first();

                    $locationLabel = '—';

                    if ($topLoc?->charity_location_id) {
                        $loc = CharityLocation::with('main_location')
                            ->find($topLoc->charity_location_id);

                        $main = $loc?->main_location?->name;
                        $sub  = $loc?->name;

                        $locationLabel = ($main && $sub) ? "{$main} - {$sub}" : ($sub ?? '—');
                    }

                    return [
                        'device_brand_id' => (int) $row->device_brand_id,
                        'device_model_id' => (int) $row->device_model_id,
                        'brand'           => (string) $row->brand_name,
                        'model'           => (string) $row->model_name,

                        // ✅ This is what you’ll show in the donut list
                        'label'           => trim($row->brand_name . ' - ' . $row->model_name),

                        // ✅ still show where it mainly comes from
                        'location_label'  => $locationLabel,

                        // ✅ success-only totals (as you wanted)
                        'total_amount'    => (float) $row->total_amount,
                        'tx_count'        => (int) $row->tx_count,
                    ];
                });

            return response()->json([
                'success' => true,
                'data'    => $rows,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }



    public function topLocation(): JsonResponse
    {

        try {
            $rows = CharityTransactions::query()
                ->where('status', 'success') // ✅ success-only
                ->select('charity_location_id', DB::raw('SUM(total_amount) as total_amount'))
                ->groupBy('charity_location_id')
                ->orderByDesc('total_amount')
                ->with(['charityLocation.main_location']) // ✅ load main location
                ->limit(5)
                ->get()
                ->map(function ($row) {
                    $main = $row->charityLocation?->main_location?->name;
                    $sub  = $row->charityLocation?->name;

                    $label = ($main && $sub)
                        ? ($main . ' - ' . $sub)
                        : ($sub ?? ('Location #' . $row->charity_location_id));

                    return [
                        'charity_location_id' => $row->charity_location_id,
                        'label'               => $label,
                        'total_amount'        => (float) $row->total_amount,
                    ];
                });
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }



    public function topBanks(): JsonResponse
    {
        $rows = CharityTransactions::query()
            ->where('status', 'success') // ✅ success-only
            ->select('bank_transaction_id', DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('bank_transaction_id')
            ->orderByDesc('total_amount')
            ->with(['bank'])
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $label = $row->bank?->name
                    ?? 'Bank #' . $row->bank_transaction_id;

                return [
                    'bank_transaction_id' => $row->bank_transaction_id,
                    'label'               => $label,
                    'total_amount'        => (float) $row->total_amount,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }



   public function aiDashboardSearch(Request $request): JsonResponse
    {
        // ---------------------------
        // Date range (default last 30 days)
        // ---------------------------
        $from = $request->input('from');
        $to   = $request->input('to');

        if (!$from || !$to) {
            $toDt   = Carbon::now()->endOfDay();
            $fromDt = Carbon::now()->subDays(30)->startOfDay();
        } else {
            $fromDt = Carbon::parse($from)->startOfDay();
            $toDt   = Carbon::parse($to)->endOfDay();
        }

        // If you are sending to AI, avoid gigantic payloads by default
        // You can still increase via ?tx_limit=20000 for example
        $txLimit = (int) $request->query('tx_limit', 5000);
        $txLimit = max(100, min($txLimit, 20000));

        // ---------------------------
        // BASE QUERY (success only)
        // ---------------------------
        $baseQuery = CharityTransactions::query()
            ->whereBetween('created_at', [$fromDt, $toDt])
            ->where('status', 'success');

        // ---------------------------
        // Transactions (success) with relations
        // ---------------------------
        $transactions = (clone $baseQuery)
            ->with([
                // adjust relation names if yours differ
                'bank:id,name,short_name',
                'country:id,name',
                'region:id,name,country_id',
                'district:id,name,region_id',
                'city:id,name,region_id,district_id',
                'charityLocation:id,name,country_id,region_id,district_id,city_id,main_location_id,organization_id,is_active',
                'charityLocation.mainLocation:id,name,city_id,company_id',
                'charityLocation.mainLocation.company:id,name',
                'device:id,kiosk_id,device_brand_id,device_model_id,bank_id,country_id,region_id,district_id,city_id,main_location_id,charity_location_id,status,installed_at,login_generated_token,model_number',
                'device.deviceModel:id,name,device_brand_id',
                'device.deviceBrand:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit($txLimit)
            ->get();

        $transactionsTotalRows = (clone $baseQuery)->count();

        // ---------------------------
        // Summary totals
        // ---------------------------
        $totalSuccessfulAmount = (float) (clone $baseQuery)->sum(DB::raw('total_amount::numeric'));
        $totalSuccessfulCount  = (int) (clone $baseQuery)->count();

        // ---------------------------
        // Top devices (count + amount)
        // ---------------------------
        $byDevices = (clone $baseQuery)
            ->select(
                'device_id',
                DB::raw('COUNT(*) as transactions_count'),
                DB::raw('SUM(total_amount::numeric) as total_amount')
            )
            ->groupBy('device_id')
            ->with([
                'device:id,kiosk_id,device_brand_id,device_model_id,main_location_id,charity_location_id,status',
                'device.deviceModel:id,name,device_brand_id',
                'device.deviceBrand:id,name',
            ])
            ->orderByDesc('transactions_count')
            ->limit(50)
            ->get();

        // ---------------------------
        // Daily totals
        // ---------------------------
        $dailyTotals = (clone $baseQuery)
            ->selectRaw("DATE(created_at) as date")
            ->selectRaw("SUM(total_amount::numeric) as total_amount")
            ->selectRaw("COUNT(*) as transactions_count")
            ->groupByRaw("DATE(created_at)")
            ->orderBy('date')
            ->get();

        // ---------------------------
        // Sales by hour (weekday x hour)
        // weekday: 0=Sunday ... 6=Saturday (Postgres EXTRACT(DOW))
        // ---------------------------
        $salesByHour = (clone $baseQuery)
            ->selectRaw("EXTRACT(DOW FROM created_at) as weekday")
            ->selectRaw("EXTRACT(HOUR FROM created_at) as hour")
            ->selectRaw("COUNT(*) as transactions_count")
            ->selectRaw("SUM(total_amount::numeric) as total_amount")
            ->groupByRaw("EXTRACT(DOW FROM created_at), EXTRACT(HOUR FROM created_at)")
            ->orderByRaw("weekday asc, hour asc")
            ->get();

        // ---------------------------
        // Top banks / countries / regions / districts / cities / main locations / charity locations
        // (make sure these FK columns match your schema)
        // ---------------------------
        $byBank = (clone $baseQuery)
            ->select('bank_transaction_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('bank_transaction_id')
            ->with('bank')
            ->orderByDesc('transactions_count')
            ->limit(30)
            ->get();

        $byCountry = (clone $baseQuery)
            ->select('country_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('country_id')
            ->with('country')
            ->orderByDesc('transactions_count')
            ->limit(30)
            ->get();

        $byRegion = (clone $baseQuery)
            ->select('region_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('region_id')
            ->with('region')
            ->orderByDesc('transactions_count')
            ->limit(30)
            ->get();

        $byDistrict = (clone $baseQuery)
            ->select('district_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('district_id')
            ->with('district')
            ->orderByDesc('transactions_count')
            ->limit(30)
            ->get();

        $byCity = (clone $baseQuery)
            ->select('city_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('city_id')
            ->with('city')
            ->orderByDesc('transactions_count')
            ->limit(30)
            ->get();

        $byMainLocation = (clone $baseQuery)
            ->select('main_location_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('main_location_id')
            ->with('mainLocation')
            ->orderByDesc('transactions_count')
            ->limit(30)
            ->get();

        $byCharityLocation = (clone $baseQuery)
            ->select('charity_location_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('charity_location_id')
            ->with('charityLocation')
            ->orderByDesc('transactions_count')
            ->limit(30)
            ->get();

        // ---------------------------
        // Reference data for AI (ALL locations/devices)
        // ---------------------------
        $reference = [
            'countries' => Country::select('id', 'name', 'iso_code')->orderBy('name')->get(),
            'regions' => Region::select('id', 'name', 'country_id', 'type', 'code')->orderBy('name')->get(),
            'districts' => District::select('id', 'name', 'region_id')->orderBy('name')->get(),
            'cities' => City::select('id', 'name', 'region_id', 'district_id')->orderBy('name')->get(),

            'main_locations' => MainLocation::select('id', 'name', 'city_id', 'company_id')
                ->with('company:id,name')
                ->orderBy('name')
                ->get(),

            'charity_locations' => CharityLocation::select(
                    'id','name','country_id','region_id','district_id','city_id','main_location_id',
                    'organization_id','is_active'
                )
                ->orderBy('name')
                ->get(),

            'banks' => Banks::select('id','name','short_name','country_id','swift_code','is_active')
                ->orderBy('name')
                ->get(),

            'device_brands' => DeviceBrand::select('id','name')->orderBy('name')->get(),
            'device_models' => DeviceModel::select('id','name','device_brand_id')->orderBy('name')->get(),

            // all devices, but with relations so AI can map them to locations
            'devices' => Devices::select(
                    'id','kiosk_id','device_brand_id','device_model_id','bank_id',
                    'country_id','region_id','district_id','city_id','main_location_id','charity_location_id',
                    'status','installed_at','login_generated_token','model_number','companies_id'
                )
                ->with([
                    'deviceBrand:id,name',
                    'deviceModel:id,name,device_brand_id',
                    'bank:id,name,short_name',
                    'country:id,name',
                    'region:id,name,country_id',
                    'district:id,name,region_id',
                    'city:id,name,region_id,district_id',
                    'mainLocation:id,name,city_id,company_id',
                    'mainLocation.company:id,name',
                    'charityLocation:id,name,main_location_id,city_id,district_id,region_id,country_id',
                ])
                ->orderByDesc('id')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'range' => [
                'from' => $fromDt->toDateTimeString(),
                'to'   => $toDt->toDateTimeString(),
            ],
            'reference' => $reference,

            'charity' => [
                'transactions' => $transactions,
                'transactions_total_rows' => $transactionsTotalRows,
                'transactions_limit' => $txLimit,

                'summary' => [
                    'total_success_amount' => $totalSuccessfulAmount,
                    'total_success_count'  => $totalSuccessfulCount,
                ],

                'by_devices'          => $byDevices,
                'by_bank'             => $byBank,
                'by_country'          => $byCountry,
                'by_region'           => $byRegion,
                'by_district'         => $byDistrict,
                'by_city'             => $byCity,
                'by_main_location'    => $byMainLocation,
                'by_charity_location' => $byCharityLocation,

                'daily_totals'        => $dailyTotals,
                'sales_by_hour'       => $salesByHour,
            ],
        ]);
    }


    public function heatmap(Request $request): JsonResponse
    {
        // Optional date filter: ?from=2025-11-01&to=2025-11-30
        $from = $request->input('from');
        $to   = $request->input('to');

        if ($from && $to) {
            $from = Carbon::parse($from)->startOfDay();
            $to   = Carbon::parse($to)->endOfDay();
        } else {
            // default: last 30 days
            $to   = Carbon::now();
            $from = Carbon::now()->subDays(30);
        }

        // Optional: allow filtering later if you ever want it
        // ?status=success|fail|all  (default all)
        $status = $request->input('status', 'all');

        $q = CharityTransactions::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('created_at', [$from, $to]);

        if ($status === 'success') {
            $q->where('status', 'success');
        } elseif ($status === 'fail') {
            $q->where('status', 'fail');
        } // else all

        $rows = $q
            ->groupBy('latitude', 'longitude')
            ->select(
                'latitude',
                'longitude',
                DB::raw('COUNT(*) as transactions_count'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"),
                DB::raw("SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) as fail_count"),
                DB::raw('SUM(total_amount) as total_amount'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN total_amount ELSE 0 END) as success_amount"),
                DB::raw("SUM(CASE WHEN status = 'fail' THEN total_amount ELSE 0 END) as fail_amount")
            )
            ->get()
            ->map(function ($row) {
                return [
                    'lat'               => (float) $row->latitude,
                    'lng'               => (float) $row->longitude,

                    // ✅ totals (all)
                    'transactions_count' => (int) $row->transactions_count,
                    'total_amount'      => (float) $row->total_amount,

                    // ✅ breakdown
                    'success_count'     => (int) $row->success_count,
                    'fail_count'        => (int) $row->fail_count,
                    'success_amount'    => (float) $row->success_amount,
                    'fail_amount'       => (float) $row->fail_amount,
                ];
            });

        return response()->json([
            'success' => true,
            'meta'    => [
                'from' => $from->toDateTimeString(),
                'to'   => $to->toDateTimeString(),
                'status' => $status,
            ],
            'data'    => $rows,
        ]);
    }
}
