<?php

namespace App\Http\Controllers;

use App\Models\DeviceBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\CharityTransactions;

class CharityDeviceStatusController extends Controller
{
    //


        /**
     * Brand → Model → Device tree for the selects.
     */
    public function filters()
    {
        // thanks to deviceModels() and devices() we can eager load tree
        $brands = DeviceBrand::with(['deviceModels.devices'])
            ->orderBy('name')
            ->get();

        $data = $brands->map(function ($brand) {
            return [
                'id'   => $brand->id,
                'name' => $brand->name,
                'models' => $brand->deviceModels->map(function ($model) {
                    return [
                        'id'   => $model->id,
                        'name' => $model->name,
                        'devices' => $model->devices->map(function ($device) {
                            return [
                                'id'            => $device->id,
                                'name'          => $device->model_number,
                                'serial_number' => $device->serial_number ?? null,
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Status stats + transactions for a single device.
     *
     * Query params:
     *  - device_id (required)
     *  - range: 7d | 30d | 6m | custom (default: 7d)
     *  - from, to (YYYY-MM-DD) for custom
     */
    public function index(Request $request)
    {
        $deviceId = $request->integer('device_id');
        if (!$deviceId) {
            return response()->json([
                'message' => 'device_id is required',
            ], 422);
        }

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

        $base = CharityTransactions::with([
                'device.deviceModel.deviceBrand',
                'bank',
                'charityLocation',
            ])
            ->where('device_id', $deviceId)
            ->whereBetween('created_at', [$start, $end]);

        // Totals
        $successTotal = (clone $base)
            ->where('status', 'success')
            ->sum('total_amount');

        $failedTotal = (clone $base)
            ->where('status', 'fail')
            ->sum('total_amount');

        // Successful transactions
        $successTransactions = (clone $base)
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->get();

        // Failed transactions
        $failedTransactions = (clone $base)
            ->where('status', 'fail')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'totals' => [
                'success' => (float) $successTotal,
                'failed'  => (float) $failedTotal,
            ],
            'success_transactions' => $successTransactions,
            'failed_transactions'  => $failedTransactions,
        ]);
    }
}
