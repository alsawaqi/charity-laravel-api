<?php

namespace App\Http\Controllers;

use App\Models\Banks;
use App\Models\Company;
use App\Models\Devices;
use App\Models\MainLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\CharityTransactions;

class CharityDeviceStatusController extends Controller
{
    private function successStatuses(): array
    {
        return ['success', 'successful'];
    }

    private function failedStatuses(): array
    {
        return ['fail', 'failed'];
    }

    private function resolveRanges(Request $request): array
    {
        $range = (string) $request->input('range', '7d');
        $from  = $request->input('from');
        $to    = $request->input('to');

        $currentEnd = Carbon::today()->endOfDay();

        switch ($range) {
            case '30d':
                $currentStart = $currentEnd->copy()->subDays(29)->startOfDay();
                $currentLabel = 'Last 30 Days';
                break;
            case '6m':
                $currentStart = $currentEnd->copy()->subMonthsNoOverflow(6)->startOfDay();
                $currentLabel = 'Last 6 Months';
                break;
            case 'custom':
                if (!$from || !$to) {
                    abort(response()->json([
                        'message' => 'From and to dates are required for custom range',
                    ], 422));
                }
                $currentStart = Carbon::parse($from)->startOfDay();
                $currentEnd   = Carbon::parse($to)->endOfDay();
                $currentLabel = 'Custom Range';
                break;
            case '7d':
            default:
                $currentStart = $currentEnd->copy()->subDays(6)->startOfDay();
                $currentLabel = 'Last 7 Days';
                break;
        }

        $days = max(1, $currentStart->copy()->startOfDay()->diffInDays($currentEnd->copy()->startOfDay()) + 1);
        $previousEnd = $currentStart->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();
        $previousLabel = $range === 'custom' ? 'Previous Matching Period' : "Previous {$days} Days";

        return [
            'range' => $range,
            'current_start' => $currentStart,
            'current_end' => $currentEnd,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'current_label' => $currentLabel,
            'previous_label' => $previousLabel,
            'days' => $days,
        ];
    }

    private function applyTransactionFilters($query, Request $request): void
    {
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('main_location_id')) {
            $query->where('main_location_id', $request->integer('main_location_id'));
        }

        if ($request->filled('charity_location_id')) {
            $query->where('charity_location_id', $request->integer('charity_location_id'));
        }

        if ($request->filled('bank_id')) {
            $query->where('bank_transaction_id', $request->integer('bank_id'));
        }
    }

    private function deltaPercent(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return (($current - $previous) / $previous) * 100;
    }

    private function buildDeviceLabel(Devices $device): string
    {
        $modelName = $device->DeviceModel?->name ?: ($device->model_number ?: ('Device #' . $device->id));
        $kiosk = $device->kiosk_id ?: '—';
        $terminal = $device->terminal_id ?: '—';

        return sprintf('%s | Kiosk: %s | Terminal: %s', $modelName, $kiosk, $terminal);
    }

    public function filters(Request $request)
    {
        $range = $this->resolveRanges($request);
        $currentStart = $range['current_start'];
        $currentEnd = $range['current_end'];

        $companies = Company::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $banks = Banks::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $mainLocations = MainLocation::query()
            ->select('id', 'name', 'company_id')
            ->with([
                'charityLocations' => function ($q) {
                    $q->select('id', 'name', 'main_location_id')->orderBy('name');
                },
            ])
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->integer('company_id'));
            })
            ->orderBy('name')
            ->get();

        $onlyFailures = $request->boolean('only_devices_with_failures');
        $onlyZeroTransactions = $request->boolean('only_devices_with_zero_transactions');

        $devicesQuery = Devices::query()
            ->select([
                'id',
                'device_brand_id',
                'device_model_id',
                'companies_id',
                'main_location_id',
                'charity_location_id',
                'bank_id',
                'model_number',
                'kiosk_id',
                'terminal_id',
            ])
            ->with([
                'DeviceBrand:id,name',
                'DeviceModel:id,name',
            ])
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('companies_id', $request->integer('company_id'));
            })
            ->when($request->filled('main_location_id'), function ($q) use ($request) {
                $q->where('main_location_id', $request->integer('main_location_id'));
            })
            ->when($request->filled('charity_location_id'), function ($q) use ($request) {
                $q->where('charity_location_id', $request->integer('charity_location_id'));
            })
            ->when($request->filled('bank_id'), function ($q) use ($request) {
                $q->where('bank_id', $request->integer('bank_id'));
            })
            ->orderBy('id');

        if ($onlyFailures && $onlyZeroTransactions) {
            $devicesQuery->whereRaw('1 = 0');
        } elseif ($onlyFailures) {
            $failedStatuses = $this->failedStatuses();

            $devicesQuery->whereExists(function ($q) use ($currentStart, $currentEnd, $request, $failedStatuses) {
                $q->select(DB::raw(1))
                    ->from('charity_transactions as ct')
                    ->whereColumn('ct.device_id', 'devices.id')
                    ->whereBetween('ct.created_at', [$currentStart, $currentEnd])
                    ->whereIn('ct.status', $failedStatuses);

                $this->applyTransactionFilters($q, $request);
            });
        } elseif ($onlyZeroTransactions) {
            $devicesQuery->whereNotExists(function ($q) use ($currentStart, $currentEnd, $request) {
                $q->select(DB::raw(1))
                    ->from('charity_transactions as ct')
                    ->whereColumn('ct.device_id', 'devices.id')
                    ->whereBetween('ct.created_at', [$currentStart, $currentEnd]);

                $this->applyTransactionFilters($q, $request);
            });
        }

        $devices = $devicesQuery->get();

        $brands = $devices
            ->groupBy('device_brand_id')
            ->map(function ($brandDevices) {
                $firstBrand = $brandDevices->first();

                return [
                    'id' => (int) ($firstBrand->device_brand_id ?? 0),
                    'name' => $firstBrand->DeviceBrand?->name ?: 'Unknown Brand',
                    'models' => $brandDevices
                        ->groupBy('device_model_id')
                        ->map(function ($modelDevices) {
                            $firstModel = $modelDevices->first();

                            return [
                                'id' => (int) ($firstModel->device_model_id ?? 0),
                                'name' => $firstModel->DeviceModel?->name ?: 'Unknown Model',
                                'devices' => $modelDevices
                                    ->map(function (Devices $device) {
                                        return [
                                            'id' => (int) $device->id,
                                            'name' => $device->model_number ?: ('Device #' . $device->id),
                                            'label' => $this->buildDeviceLabel($device),
                                            'model_name' => $device->DeviceModel?->name,
                                            'kiosk_id' => $device->kiosk_id,
                                            'terminal_id' => $device->terminal_id,
                                        ];
                                    })
                                    ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                                    ->values(),
                            ];
                        })
                        ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                        ->values(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'data' => [
                'brands' => $brands,
                'companies' => $companies,
                'banks' => $banks,
                'main_locations' => $mainLocations,
                'range' => [
                    'current_label' => $range['current_label'],
                    'previous_label' => $range['previous_label'],
                    'current_start' => $currentStart->toDateString(),
                    'current_end' => $currentEnd->toDateString(),
                ],
            ],
        ]);
    }

    public function index(Request $request)
    {
        $deviceId = $request->integer('device_id');
        if (!$deviceId) {
            return response()->json([
                'message' => 'device_id is required',
            ], 422);
        }

        $range = $this->resolveRanges($request);
        $currentStart = $range['current_start'];
        $currentEnd = $range['current_end'];
        $previousStart = $range['previous_start'];
        $previousEnd = $range['previous_end'];

        $device = Devices::query()
            ->with([
                'DeviceBrand:id,name',
                'DeviceModel:id,name',
                'company:id,name',
                'mainLocation:id,name',
                'charityLocation:id,name',
                'bank:id,name',
            ])
            ->find($deviceId);

        if (!$device) {
            return response()->json([
                'message' => 'Device not found',
            ], 404);
        }

        $base = CharityTransactions::query()
            ->with([
                'device.DeviceModel.deviceBrand',
                'bank:id,name',
                'company:id,name',
                'mainLocation:id,name',
                'charityLocation:id,name',
            ])
            ->where('device_id', $deviceId)
            ->whereBetween('created_at', [$currentStart, $currentEnd]);

        $this->applyTransactionFilters($base, $request);

        $previousBase = CharityTransactions::query()
            ->where('device_id', $deviceId)
            ->whereBetween('created_at', [$previousStart, $previousEnd]);

        $this->applyTransactionFilters($previousBase, $request);

        $successStatuses = $this->successStatuses();
        $failedStatuses = $this->failedStatuses();

        $successTotal = (float) (clone $base)
            ->whereIn('status', $successStatuses)
            ->sum('total_amount');

        $failedTotal = (float) (clone $base)
            ->whereIn('status', $failedStatuses)
            ->sum('total_amount');

        $previousSuccessTotal = (float) (clone $previousBase)
            ->whereIn('status', $successStatuses)
            ->sum('total_amount');

        $previousFailedTotal = (float) (clone $previousBase)
            ->whereIn('status', $failedStatuses)
            ->sum('total_amount');

        $successTransactions = (clone $base)
            ->whereIn('status', $successStatuses)
            ->orderByDesc('created_at')
            ->get();

        $failedTransactions = (clone $base)
            ->whereIn('status', $failedStatuses)
            ->orderByDesc('created_at')
            ->get();

        $currentTxCount = (int) (clone $base)->count();
        $previousTxCount = (int) (clone $previousBase)->count();
        $currentFailedCount = (int) (clone $base)->whereIn('status', $failedStatuses)->count();
        $currentSuccessCount = (int) (clone $base)->whereIn('status', $successStatuses)->count();
        $previousFailedCount = (int) (clone $previousBase)->whereIn('status', $failedStatuses)->count();
        $previousSuccessCount = (int) (clone $previousBase)->whereIn('status', $successStatuses)->count();

        $byHourRaw = (clone $base)
            ->whereIn('status', $successStatuses)
            ->selectRaw('((EXTRACT(DOW FROM created_at)::int + 6) % 7) as weekday_index, EXTRACT(HOUR FROM created_at)::int as hour_of_day, SUM(total_amount) as total_amount')
            ->groupBy('weekday_index', 'hour_of_day')
            ->orderBy('weekday_index')
            ->orderBy('hour_of_day')
            ->get();

        $weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $matrix = [];
        foreach (range(0, 23) as $hour) {
            $matrix[$hour] = array_fill(0, 7, 0.0);
        }

        foreach ($byHourRaw as $row) {
            $weekday = (int) $row->weekday_index;
            $hour = (int) $row->hour_of_day;

            if (isset($matrix[$hour][$weekday])) {
                $matrix[$hour][$weekday] = (float) $row->total_amount;
            }
        }

        $hourSeries = [];
        foreach (range(0, 23) as $hour) {
            $hourSeries[] = [
                'name' => sprintf('%02d:00', $hour),
                'data' => $matrix[$hour],
            ];
        }

        return response()->json([
            'meta' => [
                'current_label' => $range['current_label'],
                'previous_label' => $range['previous_label'],
                'current_start' => $currentStart->toDateString(),
                'current_end' => $currentEnd->toDateString(),
                'previous_start' => $previousStart->toDateString(),
                'previous_end' => $previousEnd->toDateString(),
            ],
            'device' => [
                'id' => (int) $device->id,
                'label' => $this->buildDeviceLabel($device),
                'brand' => $device->DeviceBrand?->name,
                'model' => $device->DeviceModel?->name,
                'company' => $device->company?->name,
                'main_location' => $device->mainLocation?->name,
                'charity_location' => $device->charityLocation?->name,
                'bank' => $device->bank?->name,
            ],
            'totals' => [
                'success' => $successTotal,
                'failed' => $failedTotal,
                'tx_count' => $currentTxCount,
                'success_count' => $currentSuccessCount,
                'failed_count' => $currentFailedCount,
            ],
            'comparison' => [
                'previous_success' => $previousSuccessTotal,
                'previous_failed' => $previousFailedTotal,
                'previous_tx_count' => $previousTxCount,
                'previous_success_count' => $previousSuccessCount,
                'previous_failed_count' => $previousFailedCount,
                'success_delta' => $successTotal - $previousSuccessTotal,
                'failed_delta' => $failedTotal - $previousFailedTotal,
                'success_delta_pct' => $this->deltaPercent($successTotal, $previousSuccessTotal),
                'failed_delta_pct' => $this->deltaPercent($failedTotal, $previousFailedTotal),
            ],
            'success_transactions' => $successTransactions,
            'failed_transactions' => $failedTransactions,
            'sales_by_hour' => [
                'categories' => $weekdayNames,
                'series' => $hourSeries,
            ],
        ]);
    }
}