<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesCharityReportFilters;
use App\Models\CharityTransactions;
use App\Models\CharityTransactionShare;
use App\Models\CommissionProfiles;
use App\Models\CommissionProfilesShares;
use App\Models\Devices;
use App\Services\ScalefusionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CharityTransactionsController extends Controller
{
    use ResolvesCharityReportFilters;

    public function index(Request $request)
    {
        ['start' => $start, 'end' => $end] = $this->resolveCharityDateRange('today');

        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min($perPage, 50));

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
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', $this->charitySuccessStatuses())
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
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
        try {
            ['start' => $start, 'end' => $end] = $this->resolveCharityRangeFromRequest($request);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Invalid date range. Use YYYY-MM-DD.',
            ], 422);
        }

        $perPage = max(1, min((int) $request->input('per_page', 10), 100));
        $successPage = max(1, (int) $request->input('success_page', 1));
        $failedPage = max(1, (int) $request->input('failed_page', 1));

        $base = CharityTransactions::with([
            'device',
            'device.DeviceModel',
            'device.DeviceModel.DeviceBrand',
            'bank',
            'charityLocation',
            'charityLocation.main_location',
            'charitytransactionshares',
            'charitytransactionshares.comissionProfileShare',
            'charitytransactionshares.comissionProfileShare.organization',
        ])->whereBetween('created_at', [$start, $end]);

        $successQuery = (clone $base)->whereIn('status', $this->charitySuccessStatuses());
        $failedQuery = (clone $base)->whereIn('status', $this->charityFailedStatuses());

        $successAmount = (float) (clone $successQuery)->sum('total_amount');
        $successCount = (int) (clone $successQuery)->count();
        $failedAmount = (float) (clone $failedQuery)->sum('total_amount');
        $failedCount = (int) (clone $failedQuery)->count();

        $success = (clone $successQuery)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'success_page', $successPage);

        $failed = (clone $failedQuery)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'failed_page', $failedPage);

        return response()->json([
            'totals' => [
                'success' => ['amount' => $successAmount, 'count' => $successCount],
                'failed' => ['amount' => $failedAmount, 'count' => $failedCount],
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
        if (! in_array($sortBy, $allowedSort, true)) {
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
            if (in_array($status, ['success', 'successful', 'fail', 'failed', 'pending', 'Cancelled', 'cancelled'], true)) {
                $this->applyNormalizedStatusFilter($base, $status);
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
                            ->orWhere('terminal_id', 'ilike', $like)
                            ->orWhere('device_code', 'ilike', $like);
                    });

                if (ctype_digit($search)) {
                    $qq->orWhere('id', (int) $search);
                }
            });
        }

        $allAmount = (float) (clone $base)->sum('total_amount');
        $allCount = (int) (clone $base)->count();

        $successAmount = (float) (clone $base)->whereIn('status', $this->charitySuccessStatuses())->sum('total_amount');
        $successCount = (int) (clone $base)->whereIn('status', $this->charitySuccessStatuses())->count();

        $failAmount = (float) (clone $base)->whereIn('status', $this->charityFailedStatuses())->sum('total_amount');
        $failCount = (int) (clone $base)->whereIn('status', $this->charityFailedStatuses())->count();

        $cancelledAmount = (float) (clone $base)->whereIn('status', $this->charityCancelledStatuses())->sum('total_amount');
        $cancelledCount = (int) (clone $base)->whereIn('status', $this->charityCancelledStatuses())->count();

        $paginator = (clone $base)
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);

        $collection = $paginator->getCollection();

        $ids = $collection->map(function ($tx) {
            return optional($tx->device)->kiosk_id;
        })->filter()->unique()->values()->all();

        if (! empty($ids)) {
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
                $device = $this->resolveDonationDevice($request->input('id'));

                $commissionProfile = CommissionProfiles::where('id', $device->commission_profile_id)->first();
                if (! $commissionProfile) {
                    throw new \RuntimeException('Commission profile not found for the selected device.');
                }

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
                    'terminal_id' => $device->terminal_id ?: $request->input('terminalId'),
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
                $device = $this->resolveDonationDevice($request->input('id'));

                $commissionProfile = CommissionProfiles::where('id', $device->commission_profile_id)->first();
                if (! $commissionProfile) {
                    throw new \RuntimeException('Commission profile not found for the selected device.');
                }

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
                    'terminal_id' => $device->terminal_id ?: $request->input('terminalId'),
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

    public function store_bank_nizwa(Request $request)
    {
        try {
            $result = DB::transaction(function () use ($request) {
                $device = $this->resolveDonationDevice($request->input('id') ?? $request->input('device_code'));

                $commissionProfile = CommissionProfiles::where('id', $device->commission_profile_id)->first();
                if (! $commissionProfile) {
                    throw new \RuntimeException('Commission profile not found for the selected device.');
                }

                $commissionProfileShares = CommissionProfilesShares::where('commission_profile_id', $commissionProfile->id)->get();
                $receipt = $this->normalizeBankReceipt($request->input('receipt') ?? $request->input('bank_response'));
                $status = $this->resolveBankNizwaStatus($receipt, $request->input('status'));

                $bankResponse = is_array($receipt) ? $receipt : [];
                $bankResponse['_charity_status'] = $status;

                $organizationId = optional($device->charityLocation)->organization_id;

                $charity = CharityTransactions::create([
                    'device_id' => $device->id,
                    'commission_profile_id' => $commissionProfile->id,
                    'total_amount' => $request->input('amount'),
                    'bank_response' => $bankResponse,
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
                    'terminal_id' => $device->terminal_id
                        ?: $request->input('terminalId')
                        ?: $request->input('terminal_id')
                        ?: $device->device_code,
                ]);

                $shareRows = [];

                foreach ($commissionProfileShares as $share) {
                    $percentage = (float) $share->percentage;
                    $shareAmount = round($request->input('amount') * $percentage / 100, 3);

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
                'message' => 'Bank Nizwa charity transaction stored successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing Bank Nizwa charity transaction: '.$e->getMessage(),
            ], 500);
        }
    }

    private function normalizeBankReceipt(mixed $rawReceipt): ?array
    {
        if (is_string($rawReceipt)) {
            $decoded = json_decode($rawReceipt, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [
                'raw_response' => $rawReceipt,
            ];
        }

        if (is_array($rawReceipt)) {
            return $rawReceipt;
        }

        return null;
    }

    private function resolveBankNizwaStatus(?array $receipt, mixed $requestStatus = null): string
    {
        $status = $this->normalizeBankNizwaStatusValue($requestStatus);
        if ($status !== null) {
            return $status;
        }

        if (! $receipt) {
            return 'fail';
        }

        foreach (['status', 'transaction_status', 'transactionStatus', 'payment_status'] as $key) {
            $status = $this->normalizeBankNizwaStatusValue($receipt[$key] ?? null);
            if ($status !== null) {
                return $status;
            }
        }

        foreach (['responseCode', 'response_code', 'DE39'] as $key) {
            $responseCode = trim((string) ($receipt[$key] ?? ''));
            if ($responseCode === '00') {
                return 'success';
            }

            if ($responseCode !== '') {
                return 'fail';
            }
        }

        foreach (['message', 'responseMessage', 'response_description', 'responseDescription'] as $key) {
            $message = strtolower(trim((string) ($receipt[$key] ?? '')));
            if ($message === '') {
                continue;
            }

            if (str_contains($message, 'success') || str_contains($message, 'approved')) {
                return 'success';
            }

            if (str_contains($message, 'cancel')) {
                return 'Cancelled';
            }

            if (
                str_contains($message, 'fail') ||
                str_contains($message, 'error') ||
                str_contains($message, 'declin') ||
                str_contains($message, 'invalid') ||
                str_contains($message, 'locked')
            ) {
                return 'fail';
            }
        }

        return 'fail';
    }

    private function normalizeBankNizwaStatusValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'success' : 'fail';
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'true', '1', 'success', 'successful', 'approved', 'complete', 'completed' => 'success',
            'cancel', 'cancelled', 'canceled' => 'Cancelled',
            'false', '0', 'fail', 'failed', 'failure', 'declined', 'error', 'notapproved', 'not_approved' => 'fail',
            default => null,
        };
    }

    private function resolveDonationDevice(?string $deviceIdentifier): Devices
    {
        $normalizedIdentifier = trim((string) $deviceIdentifier);

        if ($normalizedIdentifier === '') {
            throw new \RuntimeException('Device identifier is required.');
        }

        $device = Devices::query()
            ->where(function ($query) use ($normalizedIdentifier) {
                $query->where('kiosk_id', $normalizedIdentifier)
                    ->orWhere('device_code', strtoupper($normalizedIdentifier));
            })
            ->first();

        if (! $device) {
            throw new \RuntimeException("Device not found for identifier: {$normalizedIdentifier}");
        }

        return $device;
    }
}
