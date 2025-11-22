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
            ->with(['device.deviceModel']) // load relations for labels
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

        
        $charityTransactions  = CharityTransactions::with('device','device.devicemodel', 'device.deviceBrand', 'bank', 'country', 'region', 'city', 'charityLocation')
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
            ],
        ]);
    }
}
