<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionShareReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'organization_id'        => ['nullable', 'exists:organizations,id'],
            'source_organization_id' => ['nullable', 'exists:organizations,id'],
            'company_id'             => ['nullable', 'exists:companies,id'],
            'bank_id'                => ['nullable', 'exists:banks,id'],
            'commission_profile_id'  => ['nullable', 'exists:commission_profiles,id'],
            'from'                   => ['nullable', 'date'],
            'to'                     => ['nullable', 'date'],
            'status'                 => ['nullable', 'string', 'in:all,success,fail,pending,Cancelled'],
            'search'                 => ['nullable', 'string', 'max:255'],
            'page'                   => ['nullable', 'integer', 'min:1'],
            'per_page'               => ['nullable', 'integer', 'min:1', 'max:200'],
            'sortBy'                 => ['nullable', 'string'],
            'sortDir'                => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sortBy  = $validated['sortBy'] ?? 'transaction_created_at';
        $sortDir = $validated['sortDir'] ?? 'desc';

        $sortable = [
            'transaction_created_at'      => 'ct.created_at',
            'share_amount'                => 'cts.amount',
            'total_amount'                => 'ct.total_amount',
            'commission_profile_name'     => 'cp.name',
            'recipient_organization_name' => 'recipient_org.name',
            'share_label'                 => 'cps.label',
            'status'                      => 'ct.status',
            'terminal_id'                 => 'ct.terminal_id',
        ];

        $sortColumn = $sortable[$sortBy] ?? 'ct.created_at';

        $detailQuery = $this->baseQuery();
        $this->applyFilters($detailQuery, $request);

        $rows = (clone $detailQuery)
            ->select([
                'cts.id as charity_transaction_share_id',
                'cts.amount as share_amount',
                'cts.created_at as share_created_at',

                'ct.id as charity_transaction_id',
                'ct.created_at as transaction_created_at',
                'ct.total_amount',
                'ct.status',
                'ct.reference',
                'ct.terminal_id',
                'ct.bank_transaction_id as bank_id',
                'bank.name as bank_name',

                'ct.organization_id as source_organization_id',
                'source_org.name as source_organization_name',

                'ct.company_id',
                'comp.name as company_name',

                'ct.commission_profile_id',
                'cp.name as commission_profile_name',

                'cps.id as commission_profile_share_id',
                'cps.label as share_label',
                'cps.percentage as share_percentage',
                'cps.organization_id as recipient_organization_id',
                'recipient_org.name as recipient_organization_name',

                'd.kiosk_id',
                'cl.name as charity_location_name',
                'ml.name as main_location_name',
            ])
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('cts.id', 'desc')
            ->paginate($perPage);

        $summaryQuery = $this->baseQuery();
        $this->applyFilters($summaryQuery, $request);

        $sharesCount = (clone $summaryQuery)->count('cts.id');
        $totalShareAmount = (float) ((clone $summaryQuery)->sum('cts.amount') ?? 0);

        $distinctTransactionIds = (clone $summaryQuery)
            ->select('ct.id as charity_transaction_id')
            ->groupBy('ct.id');

        $transactionsCount = (int) DB::query()
            ->fromSub($distinctTransactionIds, 'tx_ids')
            ->count();

        $distinctTxSub = (clone $summaryQuery)
            ->select('ct.id as charity_transaction_id', 'ct.total_amount')
            ->groupBy('ct.id', 'ct.total_amount');

        $totalDonationAmount = (float) (DB::query()->fromSub($distinctTxSub, 'tx')->sum('tx.total_amount') ?? 0);

        $byProfileQuery = $this->baseQuery();
        $this->applyFilters($byProfileQuery, $request);
        $byProfile = (clone $byProfileQuery)
            ->selectRaw('
                ct.commission_profile_id,
                COALESCE(cp.name, ?) as commission_profile_name,
                COUNT(cts.id) as shares_count,
                COUNT(DISTINCT ct.id) as transactions_count,
                COALESCE(SUM(cts.amount), 0) as total_share_amount
            ', ['-'])
            ->groupBy('ct.commission_profile_id', 'cp.name')
            ->orderByDesc('total_share_amount')
            ->get();

        $byLabelQuery = $this->baseQuery();
        $this->applyFilters($byLabelQuery, $request);
        $byLabel = (clone $byLabelQuery)
            ->selectRaw('
                cps.label as share_label,
                cps.percentage as share_percentage,
                COUNT(cts.id) as shares_count,
                COUNT(DISTINCT ct.id) as transactions_count,
                COALESCE(SUM(cts.amount), 0) as total_share_amount
            ')
            ->groupBy('cps.label', 'cps.percentage')
            ->orderByDesc('total_share_amount')
            ->get();

        return response()->json([
            'success' => true,
            'summary' => [
                'total_share_amount'    => round($totalShareAmount, 3),
                'transactions_count'    => (int) $transactionsCount,
                'shares_count'          => (int) $sharesCount,
                'total_donation_amount' => round($totalDonationAmount, 3),
            ],
            'breakdown' => [
                'by_profile' => $byProfile,
                'by_label'   => $byLabel,
            ],
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page'    => $rows->lastPage(),
                'per_page'     => $rows->perPage(),
                'total'        => $rows->total(),
                'from'         => $rows->firstItem(),
                'to'           => $rows->lastItem(),
            ],
        ]);
    }

    private function baseQuery()
    {
        return DB::table('charity_transaction_shares as cts')
            ->join('charity_transactions as ct', 'ct.id', '=', 'cts.charity_transaction_id')
            ->leftJoin('commission_profile_shares as cps', 'cps.id', '=', 'cts.commission_profile_share_id')
            ->leftJoin('commission_profiles as cp', 'cp.id', '=', 'ct.commission_profile_id')
            ->leftJoin('organizations as recipient_org', 'recipient_org.id', '=', 'cps.organization_id')
            ->leftJoin('organizations as source_org', 'source_org.id', '=', 'ct.organization_id')
            ->leftJoin('companies as comp', 'comp.id', '=', 'ct.company_id')
            ->leftJoin('banks as bank', 'bank.id', '=', 'ct.bank_transaction_id')
            ->leftJoin('devices as d', 'd.id', '=', 'ct.device_id')
            ->leftJoin('charity_locations as cl', 'cl.id', '=', 'ct.charity_location_id')
            ->leftJoin('main_locations as ml', 'ml.id', '=', 'ct.main_location_id');
    }

    private function applyFilters($query, Request $request): void
    {
        $status = $request->query('status', 'success');
        if ($status && $status !== 'all') {
            $query->where('ct.status', $status);
        }

        if ($request->filled('organization_id')) {
            $query->where('cps.organization_id', (int) $request->query('organization_id'));
        }

        if ($request->filled('source_organization_id')) {
            $query->where('ct.organization_id', (int) $request->query('source_organization_id'));
        }

        if ($request->filled('company_id')) {
            $query->where('ct.company_id', (int) $request->query('company_id'));
        }

        if ($request->filled('bank_id')) {
            $query->where('ct.bank_transaction_id', (int) $request->query('bank_id'));
        }

        if ($request->filled('commission_profile_id')) {
            $query->where('ct.commission_profile_id', (int) $request->query('commission_profile_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('ct.created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('ct.created_at', '<=', $request->query('to'));
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('recipient_org.name', 'like', "%{$search}%")
                    ->orWhere('source_org.name', 'like', "%{$search}%")
                    ->orWhere('cp.name', 'like', "%{$search}%")
                    ->orWhere('cps.label', 'like', "%{$search}%")
                    ->orWhere('ct.terminal_id', 'like', "%{$search}%")
                    ->orWhere('d.kiosk_id', 'like', "%{$search}%")
                    ->orWhere('ct.reference', 'like', "%{$search}%")
                    ->orWhere('comp.name', 'like', "%{$search}%");
            });
        }
    }
}