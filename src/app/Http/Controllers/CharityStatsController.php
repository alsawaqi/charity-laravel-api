<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use Illuminate\Http\Request;
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
                'total_transactions'=> $totalCount,
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
}
