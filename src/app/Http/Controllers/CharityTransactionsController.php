<?php

namespace App\Http\Controllers;

use App\Models\CharityTransactions;
use App\Models\CharityTransactionShare;
use App\Models\CommissionProfiles;
use App\Models\CommissionProfilesShares;
use App\Models\Devices;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ScalefusionService;

class CharityTransactionsController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::now('Asia/Muscat')->toDateString();

        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min($perPage, 50)); // safety limit

        $paginator = CharityTransactions::with([
            'device',
            'device.devicemodel',
            'device.devicebrand',
            'charityLocation',
            'charityLocation.main_location',
            'bank',
            'charitytransactionshares',
            'charitytransactionshares.comissionProfileShare',
            'charitytransactionshares.comissionProfileShare.organization',
        ])
            ->whereDate('created_at', $today)
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(), // keep your frontend simple
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'message' => 'Charity transactions retrieved successfully.',
        ], 200);
    }

    public function index_all(Request $request)
    {
        $range = $request->input('range', '7d'); // 7d, 30d, 6m, custom
        $from = $request->input('from');
        $to = $request->input('to');

        $end = Carbon::today()->endOfDay();

        $perPage = (int) $request->input('per_page', 10);
        $perPage = max(1, min($perPage, 100)); // safety

        $successPage = (int) $request->input('success_page', 1);
        $failedPage = (int) $request->input('failed_page', 1);

        
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
                $end = Carbon::parse($to)->endOfDay();
                break;
            case 'today':
                $start = Carbon::today()->startOfDay();
                $end = Carbon::today()->endOfDay();
                break;

            case '7d':
            default:
                $start = $end->copy()->subDays(6)->startOfDay();
                break;
        }

        $base = CharityTransactions::with([
            'device',
            'device.DeviceModel',
            'device.DeviceModel.DeviceBrand',  // or DeviceModel.DeviceBrand if you want
            'bank',
            'charityLocation',
            'charityLocation.main_location',
            'charitytransactionshares',
            'charitytransactionshares.comissionProfileShare',
            'charitytransactionshares.comissionProfileShare.organization',
        ])
            ->whereBetween('created_at', [$start, $end]);

        // success + failed queries
        $successQuery = (clone $base)->where('status', 'success');
        $failedQuery = (clone $base)->where('status', 'fail');

        $successAmount = (clone $successQuery)->sum('total_amount');
        $successCount = (clone $successQuery)->count();

        $failedAmount = (clone $failedQuery)->sum('total_amount');
        $failedCount = (clone $failedQuery)->count();

        // ✅ Paginated lists (separate page params)
        $success = (clone $successQuery)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'success_page', $successPage);

        $failed = (clone $failedQuery)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'failed_page', $failedPage);

        return response()->json([
            'totals' => [
                'success' => [
                    'amount' => (float) $successAmount,
                    'count' => (int) $successCount,
                ],
                'failed' => [
                    'amount' => (float) $failedAmount,
                    'count' => (int) $failedCount,
                ],
            ],
            'success' => $success,
            'failed' => $failed,
        ]);
    }

    /**
     * Advanced transaction list for admin filtering (bank, date range, location, org, etc).
     * GET /api/charity-transactions.
     */
     public function filter(Request $request)
{
    $perPage = (int) $request->input('per_page', 20);
    $perPage = max(1, min($perPage, 100));

    $sortBy = (string) $request->input('sortBy', 'created_at');
    $sortDir = strtolower((string) $request->input('sortDir', 'desc')) === 'asc' ? 'asc' : 'desc';

    $allowedSort = ['id', 'created_at', 'total_amount', 'status', 'processed_at'];
    if (!in_array($sortBy, $allowedSort, true)) {
        $sortBy = 'created_at';
    }

    $tz = 'Asia/Muscat';
    $from = $request->input('from');
    $to = $request->input('to');

    try {
        if ($from && $to) {
            $start = Carbon::createFromFormat('Y-m-d', (string) $from, $tz)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', (string) $to, $tz)->endOfDay();
        } else {
            $end = Carbon::now($tz)->endOfDay();
            $start = $end->copy()->subDays(6)->startOfDay();
        }

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Invalid date range. Use YYYY-MM-DD.',
        ], 422);
    }

    $base = CharityTransactions::with([
        'device',
        'device.DeviceModel',
        'device.DeviceModel.DeviceBrand',
        'bank',
        'organization',
        'country',
        'region',
        'district',
        'city',
        'company',
        'mainLocation',
        'charityLocation',
        'charityLocation.main_location',
        'charitytransactionshares',
        'charitytransactionshares.comissionProfileShare',
        'charitytransactionshares.comissionProfileShare.organization',
    ])->whereBetween('created_at', [$start, $end]);

    // Only keep the filters you actually need
    if ($request->filled('bank_id')) {
        $base->where('bank_transaction_id', (int) $request->input('bank_id'));
    }

    if ($request->filled('status')) {
        $status = (string) $request->input('status');
        if (in_array($status, ['success', 'fail', 'pending', 'Cancelled'], true)) {
            $base->where('status', $status);
        }
    }

    $search = trim((string) $request->input('search', ''));
    if ($search !== '') {
        $like = "%{$search}%";

        $base->where(function ($qq) use ($search, $like) {
            // Search transaction terminal
            $qq->where('terminal_id', 'ilike', $like)

                // Optional small helpers if user pastes tx id/reference
                ->orWhere('reference', 'ilike', $like)

                // Search related device fields
                ->orWhereHas('device', function ($dq) use ($like) {
                    $dq->where('kiosk_id', 'ilike', $like)
                        ->orWhere('terminal_id', 'ilike', $like);
                });

            if (ctype_digit($search)) {
                $qq->orWhere('id', (int) $search);
            }
        });
    }

    $allAmount = (float) (clone $base)->sum('total_amount');
    $allCount = (int) (clone $base)->count();

    $successAmount = (float) (clone $base)->where('status', 'success')->sum('total_amount');
    $successCount = (int) (clone $base)->where('status', 'success')->count();

    $failAmount = (float) (clone $base)->where('status', 'fail')->sum('total_amount');
    $failCount = (int) (clone $base)->where('status', 'fail')->count();

    $cancelledAmount = (float) (clone $base)->where('status', 'Cancelled')->sum('total_amount');
    $cancelledCount = (int) (clone $base)->where('status', 'Cancelled')->count();

    $paginator = (clone $base)
        ->orderBy($sortBy, $sortDir)
        ->paginate($perPage);

    $collection = $paginator->getCollection();

    $ids = $collection->map(function ($tx) {
        return optional($tx->device)->kiosk_id;
    })->filter()->unique()->values()->all();

    if (!empty($ids)) {
        try {
            $sfMap = app(ScalefusionService::class)->findDevicesByIds($ids);
        } catch (\Throwable $e) {
            $sfMap = [];
        }

        $collection->transform(function ($tx) use ($sfMap) {
            if ($tx->relationLoaded('device') && $tx->device) {
                $key = (string) $tx->device->kiosk_id;
                $tx->device->setAttribute('scalefusion', $sfMap[$key] ?? null);
            }
            return $tx;
        });

        $paginator->setCollection($collection);
    }

    return response()->json([
        'success' => true,
        'filters' => [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'timezone' => $tz,
        ],
        'data' => $paginator->getCollection()->values(),
        'meta' => [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ],
        'totals' => [
            'all' => ['amount' => $allAmount, 'count' => $allCount],
            'success' => ['amount' => $successAmount, 'count' => $successCount],
            'fail' => ['amount' => $failAmount, 'count' => $failCount],
            'cancelled' => ['amount' => $cancelledAmount, 'count' => $cancelledCount],
        ],
        'message' => 'Filtered charity transactions',
    ], 200);
}

    public function store(Request $request)
    {
        try {
            $result = DB::transaction(function () use ($request) {
                $device = Devices::where('kiosk_id', $request->input('id'))->first();

                $commissionProfile = CommissionProfiles::where('id', $device->commission_profile_id)->first();

                $commissionProfileShares = CommissionProfilesShares::where('commission_profile_id', $commissionProfile->id)->get();

                $rawReceipt = $request->input('receipt');

                // Normalize: if it's already an array (from JSON body), use it,
                // if it's a JSON string, decode it.
                if (is_string($rawReceipt)) {
                    $receipt = json_decode($rawReceipt, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $receipt = null; // or handle error
                    }
                } elseif (is_array($rawReceipt)) {
                    $receipt = $rawReceipt;
                } else {
                    $receipt = null;
                }

                // Default
                $status = 'fail';

                if ($receipt && isset($receipt['reason'])) {
                    // Normalize to upper to be safe
                    $reason = strtoupper((string) $receipt['reason']);

                    if ($reason === 'SUCCESS') {
                        $status = 'success';
                    } elseif ($reason === 'Transaction cancelled by user') {
                        $status = 'Cancelled';
                    }
                }

                $organizationId = optional($device->charityLocation)->organization_id;

                $charity = CharityTransactions::create([
                    'device_id' => $device->id,
                    'commission_profile_id' => $commissionProfile->id,
                    'total_amount' => $request->input('amount'),
                    'bank_response' => $receipt,
                    'bank_transaction_id' => $device->bank_id,

                    'status' => $status,
                    'country_id' => $device->country_id,
                    'region_id' => $device->region_id,
                    'city_id' => $device->city_id,
                    'charity_location_id' => $device->charity_location_id,
                    'district_id' => $device->district_id,
                    'company_id' => $device->companies_id,
                    'main_location_id' => $device->main_location_id,
                    'organization_id' => $organizationId,
                    'latitude' => $request->input('latitude') ?? 0.00,
                    'longitude' => $request->input('longitude') ?? 0.00,
                    'terminal_id' => $device->terminal_id,
                ]);

                $shareRows = [];

                foreach ($commissionProfileShares as $share) {
                    $percentage = (float) $share->percentage; // or $share->percentage
                    $shareAmount = round($request->input('amount') * $percentage / 100, 3); // round as you like

                    $shareRows[] = CharityTransactionShare::create([
                        'charity_transaction_id' => $charity->id,

                        'commission_profile_share_id' => $share->id,

                        'amount' => $shareAmount,
                    ]);
                }

                return [
                    'charity' => $charity,
                    'shares' => $shareRows,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Charity transaction stored and shares calculated successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing charity transaction: '.$e->getMessage(),
            ], 500);
        }
    }

    public function store_dhofar(Request $request)
    {
        try {
            $result = DB::transaction(function () use ($request) {
                
                $device = Devices::where('kiosk_id', $request->input('id'))->first();

                $commissionProfile = CommissionProfiles::where('id', $device->commission_profile_id)->first();

                $commissionProfileShares = CommissionProfilesShares::where('commission_profile_id', $commissionProfile->id)->get();

                $rawReceipt = $request->input('receipt');

                // Normalize: if it's already an array (from JSON body), use it,
                // if it's a JSON string, decode it.
                if (is_string($rawReceipt)) {
                    $receipt = json_decode($rawReceipt, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $receipt = null; // invalid JSON
                    }
                } elseif (is_array($rawReceipt)) {
                    $receipt = $rawReceipt;
                } else {
                    $receipt = null;
                }

                // Default
                $status = 'fail';

                // New logic: check top-level "status"
                if (is_array($receipt) && isset($receipt['status'])) {
                    $receiptStatus = strtolower(trim((string) $receipt['status']));

                    if ($receiptStatus === 'success') {
                        $status = 'success';
                    }
                    // else keep it as 'fail'
                }

                $organizationId = optional($device->charityLocation)->organization_id;

                $charity = CharityTransactions::create([
                    'device_id' => $device->id,
                    'commission_profile_id' => $commissionProfile->id,
                    'total_amount' => $request->input('amount'),
                    'bank_response' => $receipt,
                    'bank_transaction_id' => $device->bank_id,

                    'status' => $status,
                    'country_id' => $device->country_id,
                    'region_id' => $device->region_id,
                    'city_id' => $device->city_id,
                    'charity_location_id' => $device->charity_location_id,
                    'district_id' => $device->district_id,
                    'company_id' => $device->companies_id,
                    'main_location_id' => $device->main_location_id,
                    'organization_id' => $organizationId,
                    'latitude' => $request->input('latitude') ?? 0.00,
                    'longitude' => $request->input('longitude') ?? 0.00,
                    'terminal_id' => $device->terminal_id,
                ]);

                $shareRows = [];

                foreach ($commissionProfileShares as $share) {
                    $percentage = (float) $share->percentage; // or $share->percentage
                    $shareAmount = round($request->input('amount') * $percentage / 100, 3); // round as you like

                    $shareRows[] = CharityTransactionShare::create([
                        'charity_transaction_id' => $charity->id,

                        'commission_profile_share_id' => $share->id,

                        'amount' => $shareAmount,
                    ]);
                }

                return [
                    'charity' => $charity,
                    'shares' => $shareRows,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Charity transaction stored and shares calculated successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing charity transaction: '.$e->getMessage(),
            ], 500);
        }
    }
}
