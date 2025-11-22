<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\CharityLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\CharityTransactions;

class CharityStatsController extends Controller
{
    //


    public function index(Request $request)
    {
        $range = $request->input('range', '7d'); // 7d, 30d, 6m, custom
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

        $baseQuery = CharityTransactions::whereBetween('created_at', [$start, $end]);

        // ✅ totals by status
        $successTotal = (clone $baseQuery)
            ->where('status', 'success')
            ->sum('total_amount');

        $failedTotal = (clone $baseQuery)
            ->where('status', 'fail')
            ->sum('total_amount');

        // ✅ bar chart: sum of total_amount (success only) per day
        $bar = (clone $baseQuery)
            ->where('status', 'success')
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as total_amount')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // ✅ top devices (success only)
        $topDevices = (clone $baseQuery)
            ->where('status', 'success')
            ->selectRaw('device_id, SUM(total_amount) as total_amount')
            ->with('device') // device()->belongsTo(Devices::class, 'device_id')
            ->groupBy('device_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                // adjust label if your device has different relation for name
                $label = optional($row->device)->name
                    ?? 'Device #' . $row->device_id;

                return [
                    'label'        => $label,
                    'total_amount' => (float) $row->total_amount,
                ];
            })
            ->values();

        // ✅ top locations (success only)
        $topLocations = (clone $baseQuery)
            ->where('status', 'success')
            ->selectRaw('charity_location_id, SUM(total_amount) as total_amount')
            ->with('charityLocation') // charityLocation()->belongsTo(CharityLocation::class, 'charity_location_id')
            ->groupBy('charity_location_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $label = optional($row->charityLocation)->name
                    ?? 'Location #' . $row->charity_location_id;

                return [
                    'label'        => $label,
                    'total_amount' => (float) $row->total_amount,
                ];
            })
            ->values();


        // ✅ Sales by hour (success only)
        


        $byHourRaw = (clone $baseQuery)
    ->where('status', 'success')
    ->selectRaw('
        ((EXTRACT(DOW FROM created_at)::int + 6) % 7) as weekday_index, 
        EXTRACT(HOUR FROM created_at)::int as hour_of_day,       
        SUM(total_amount) as total_amount
    ')
    ->groupBy('weekday_index', 'hour_of_day')
    ->orderBy('weekday_index')
    ->orderBy('hour_of_day')
    ->get();


        // We always want labels Monday..Sunday
        $weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        // Build a 24x7 matrix [hour][weekday] = amount
        $matrix = [];
        foreach (range(0, 23) as $h) {
            // 7 days, all zero to start
            $matrix[$h] = array_fill(0, 7, 0.0);
        }

        foreach ($byHourRaw as $row) {
            $w = (int) $row->weekday_index; // 0..6
            $h = (int) $row->hour_of_day;   // 0..23

            if (isset($matrix[$h][$w])) {
                $matrix[$h][$w] = (float) $row->total_amount;
            }
        }

        // Transform to Apex heatmap series: one series per hour
        $hourSeries = [];
        foreach (range(0, 23) as $h) {
            $label = sprintf('%02d:00', $h);

            // Optional: skip hours with no sales at all (shorter chart)
            // if (array_sum($matrix[$h]) <= 0) continue;

            $hourSeries[] = [
                'name' => $label,          // shows on Y axis
                'data' => $matrix[$h],     // values for Mon..Sun
            ];
        }




        return response()->json([
            'totals' => [
                'success' => (float) $successTotal,
                'failed'  => (float) $failedTotal,
            ],
            'bar' => [
                'categories' => $bar->pluck('date'),
                'data'       => $bar->pluck('total_amount')->map(fn($v) => (float) $v),
            ],
            'top_devices'   => $topDevices,
            'top_locations' => $topLocations,

            // ✅ NEW
            'sales_by_hour' => [
                'categories' => $weekdayNames, // X axis: Mon..Sun
                'series'     => $hourSeries,   // Y axis: 00:00..23:00 rows
            ],
        ]);
    }


    public function dailyTotals(): JsonResponse
    {
        // For Postgres: DATE(created_at) groups by calendar day
        $data = CharityTransactions::selectRaw('DATE(created_at) as date, SUM(total_amount) as total_amount')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }


    public function totals(): JsonResponse
    {
        $totalAmount = CharityTransactions::sum('total_amount');

        $totalCount = CharityTransactions::count();

        $totalDevices = Devices::count();

        $charityLocations = CharityLocation::count();



        return response()->json([
            'success' => true,
            'data'    => [
                'total_amount'      => $totalAmount,
                'total_transactions' => $totalCount,
                'total_devices'     => $totalDevices,
                'total_locations'   => $charityLocations,
            ],
        ]);
    }


    public function topDevices(): JsonResponse
    {
        $rows = CharityTransactions::query()
            ->select('device_id', DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('device_id')
            ->orderByDesc('total_amount')
            ->with(['device', 'device.deviceModel', 'device.deviceBrand']) // load relations for labels
            ->limit(5) // top 5 devices, adjust if you want
            ->get()
            ->map(function ($row) {
                $label = $row->device?->deviceModel?->name
                    ?? $row->device?->name
                    ?? 'Device #' . $row->device_id;

                return [
                    'device_id'    => $row->device_id,
                    'label'        => $label,
                    'total_amount' => (float) $row->total_amount,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }


    public function topLocation(): JsonResponse
    {
        $rows = CharityTransactions::query()
            ->select('charity_location_id', DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('charity_location_id')
            ->orderByDesc('total_amount')
            ->with(['charityLocation']) // load relations for labels
            ->limit(5) // top 5 devices, adjust if you want
            ->get()
            ->map(function ($row) {
                $label = $row->charityLocation?->name
                    ?? 'Location #' . $row->charity_location_id;


                return [
                    'charity_location_id' => $row->charity_location_id,
                    'label'        => $label,
                    'total_amount' => (float) $row->total_amount,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }


    public function topBanks(): JsonResponse
    {
        $rows = CharityTransactions::query()
            ->select('bank_transaction_id', DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('bank_transaction_id')
            ->orderByDesc('total_amount')
            ->with(['bank']) // load relations for labels
            ->limit(5) // top 5 devices, adjust if you want
            ->get()
            ->map(function ($row) {
                $label = $row->bank?->name
                    ?? 'Bank #' . $row->bank_transaction_id;


                return [
                    'bank_transaction_id' => $row->bank_transaction_id,
                    'label'        => $label,
                    'total_amount' => (float) $row->total_amount,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }


    public function aiDashboardSearch(Request $request): JsonResponse
    {
        $charity_loactions = CharityLocation::all();
        $devices = Devices::all();

        // Expecting ?from=2025-11-01&to=2025-11-30 for example
        $from = $request->input('from');
        $to   = $request->input('to');

        // Fallback: if no dates given, use last 30 days
        if (!$from || !$to) {
            $to = Carbon::now();
            $from = Carbon::now()->subDays(30);
        } else {
            $from = Carbon::parse($from)->startOfDay();
            $to   = Carbon::parse($to)->endOfDay();
        }


        $charityTransactions  = CharityTransactions::with('device', 'device.devicemodel', 'device.deviceBrand', 'bank', 'country', 'region', 'city', 'charityLocation')
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'success')
            ->get();


        // Base query with date filter
        $baseQuery = CharityTransactions::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'success');

        // ✅ Successful only (status = 'success')
        $successfulQuery = (clone $baseQuery)
            ->where('status', 'success');

        $totalSuccessfulAmount = $successfulQuery->sum('total_amount');
        $totalSuccessfulCount  = $successfulQuery->count();

        // 🔹 Top devices
        $byDevices = (clone $baseQuery)
            ->select('device_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('device_id')
            ->with('device', 'device.devicemodel', 'device.deviceBrand')
            ->orderByDesc('transactions_count')
            ->get();


        // 🔹 Daily totals (sum of successful transactions per day)
        $dailyTotals = (clone $baseQuery)

            ->selectRaw("DATE(created_at) as date")
            ->selectRaw("SUM(total_amount::numeric) as total_amount") // cast safe for Postgres
            ->groupByRaw("DATE(created_at)")
            ->orderBy('date')
            ->get();

        // 🔹 Top banks
        $byBank = (clone $baseQuery)
            ->select('bank_transaction_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('bank_transaction_id')
            ->with('bank')
            ->orderByDesc('transactions_count')
            ->get();

        // 🔹 Top countries
        $byCountry = (clone $baseQuery)
            ->select('country_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('country_id')
            ->with('country')
            ->orderByDesc('transactions_count')
            ->get();

        // 🔹 Top regions
        $byRegion = (clone $baseQuery)
            ->select('region_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('region_id')
            ->with('region')
            ->orderByDesc('transactions_count')
            ->get();

        // 🔹 Top cities
        $byCity = (clone $baseQuery)
            ->select('city_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('city_id')
            ->with('city')
            ->orderByDesc('transactions_count')
            ->get();

        // 🔹 Top charity locations
        $byCharityLocation = (clone $baseQuery)
            ->select('charity_location_id', DB::raw('COUNT(*) as transactions_count'))
            ->groupBy('charity_location_id')
            ->with('charityLocation')
            ->orderByDesc('transactions_count')
            ->get();

        return response()->json([
            'success' => true,
            'devices' => $devices,
            'charity_locations' => $charity_loactions,
            'charity' => [
                'transactions' => $charityTransactions,
                'summary' => [
                    'total_success_amount' => (float) $totalSuccessfulAmount,
                    'total_success_count'  => $totalSuccessfulCount,
                    'from'                 => $from->toDateTimeString(),
                    'to'                   => $to->toDateTimeString(),
                ],
                'by_devices'         => $byDevices,
                'by_bank'            => $byBank,
                'by_country'         => $byCountry,
                'by_region'          => $byRegion,
                'by_city'            => $byCity,
                'by_charity_location' => $byCharityLocation,
                'daily_totals'        => $dailyTotals,
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

        $rows = CharityTransactions::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('status', 'success')
            // ->whereBetween('created_at', [$from, $to])
            // group by exact coordinate → sum total_amount
            ->groupBy('latitude', 'longitude')
            ->select(
                'latitude',
                'longitude',
                DB::raw('SUM(total_amount) as total_amount')
            )
            ->get()
            ->map(function ($row) {
                return [
                    'lat'    => (float) $row->latitude,
                    'lng'    => (float) $row->longitude,
                    'weight' => (float) $row->total_amount, // this drives heat intensity
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }
}
