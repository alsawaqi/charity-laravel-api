<?php

namespace App\Http\Controllers;

use App\Models\Devices;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\CommissionProfiles;
use Illuminate\Support\Facades\DB;
use App\Models\CharityTransactions;
use Illuminate\Support\Facades\Auth;
use App\Models\CharityTransactionShare;
use App\Models\CommissionProfilesShares;

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
            'charitytransactionshares.comissionProfileShare.organization'
        ])
            ->whereDate('created_at', $today)
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $paginator->items(), // keep your frontend simple
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
            'message' => 'Charity transactions retrieved successfully.',
        ], 200);
    }

    public function index_all(Request $request)
    {
        $range = $request->input('range', '7d'); // 7d, 30d, 6m, custom
        $from  = $request->input('from');
        $to    = $request->input('to');

        $end = Carbon::today()->endOfDay();


        $perPage      = (int) $request->input('per_page', 10);
        $perPage      = max(1, min($perPage, 100)); // safety

        $successPage  = (int) $request->input('success_page', 1);
        $failedPage   = (int) $request->input('failed_page', 1);


        // 👇 get the org of the logged-in user
        $user = $request->user(); // or Auth::user()


        if (!$user) {
            return response()->json([
                'message' => $request->user(),

            ], 200);
        }


        $orgId = $user->organization_id;

        // If user has no organization, decide what to do:
        if (! $orgId) {
            // OPTION A: Super admin = see all orgs
            $visibleOrgIds = Organization::pluck('id')->all();

            // OPTION B: No org assigned -> error:
            // return response()->json(['message' => 'User has no organization assigned'], 422);
        } else {
            $org = Organization::with('children')->find($orgId);

            if (! $org) {
                // The org_id points to a non-existing organization
                return response()->json([
                    'message' => "Organization not found for id {$orgId}",
                ], 404);
            }

            // Use your helper on the found org
            $visibleOrgIds = $org->descendantsAndSelfIds();
        }





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
               case 'today':
    $start = Carbon::today()->startOfDay();
    $end   = Carbon::today()->endOfDay();
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
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('organization_id', $visibleOrgIds);


        // success + failed queries
        $successQuery = (clone $base)->where('status', 'success');
        $failedQuery  = (clone $base)->where('status', 'fail');

        $successAmount = (clone $successQuery)->sum('total_amount');
        $successCount  = (clone $successQuery)->count();

        $failedAmount  = (clone $failedQuery)->sum('total_amount');
        $failedCount   = (clone $failedQuery)->count();

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
                    'count'  => (int) $successCount,
                ],
                'failed' => [
                    'amount' => (float) $failedAmount,
                    'count'  => (int) $failedCount,
                ],
            ],
            'success' => $success,
            'failed'  => $failed,
        ]);
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
                    } else if ($reason === 'Transaction cancelled by user') {
                        $status = 'Cancelled';
                    }
                }


                $organizationId = optional($device->charityLocation)->organization_id;


                $charity =   CharityTransactions::create([
                    'device_id' => $device->id,
                    'commission_profile_id' =>  $commissionProfile->id,
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
                    'organization_id'       => $organizationId,
                    'latitude' => $request->input('latitude') ?? 0.00,
                    'longitude' => $request->input('longitude') ?? 0.00,
                ]);

                $shareRows = [];

                foreach ($commissionProfileShares as $share) {
                    $percentage = (float) $share->percentage; // or $share->percentage
                    $shareAmount = round($request->input('amount') * $percentage / 100, 3); // round as you like

                    $shareRows[] = CharityTransactionShare::create([
                        'charity_transaction_id'      => $charity->id,

                        'commission_profile_share_id' => $share->id,

                        'amount'    => $shareAmount,
                    ]);
                }

                return [
                    'charity' => $charity,
                    'shares'  => $shareRows,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $result,
                'message' => 'Charity transaction stored and shares calculated successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing charity transaction: ' . $e->getMessage(),
            ], 500);
        }
    }
}
