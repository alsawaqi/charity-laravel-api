<?php

namespace App\Http\Controllers;

use App\Models\Banks;
use Illuminate\Http\Request;
use App\Services\BankReconciliationPreviewService;
use App\Services\BankReconciliationCommitService;

class BankReconciliationController extends Controller
{
    public function preview(Request $request, BankReconciliationPreviewService $service)
    {
        $validated = $request->validate([
            'bank_id'        => ['required', 'integer', 'exists:banks,id'],
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
        ]);

        $bank = Banks::findOrFail((int) $validated['bank_id']);

        $preview = $service->preview(
            $bank,
            $validated['statement_date'],
            $request->file('file')
        );

        return response()->json([
            'success' => true,
            'data'    => $preview,
            'message' => 'Bank reconciliation preview generated successfully.',
        ]);
    }

        public function commit(Request $request, BankReconciliationCommitService $service)
    {
        $validated = $request->validate([
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.row_number' => ['nullable'],
            'rows.*.date' => ['nullable'],
            'rows.*.terminal_id' => ['required', 'string'],
            'rows.*.auth_code' => ['required', 'string'],
            'rows.*.gross_amount' => ['required', 'numeric'],
             'rows.*.card_no' => ['nullable', 'string'],
            'rows.*.rrn' => ['nullable', 'string'],
            'rows.*.branch_id' => ['nullable', 'string'],
            'rows.*.card_type' => ['nullable', 'string'],
            'rows.*.transaction_type' => ['nullable', 'string'],
            'rows.*.transaction_reference' => ['nullable', 'string'],
            'rows.*.related_reference' => ['nullable', 'string'],
            'rows.*.transaction_date' => ['nullable', 'string'],
            'rows.*.settlement_date' => ['nullable', 'string'],
            'rows.*.discount_amount' => ['nullable', 'numeric'],
            'rows.*.vat_amount' => ['nullable', 'numeric'],
            'rows.*.net_amount' => ['nullable', 'numeric'],
        ]);

        $bank = Banks::findOrFail((int) $validated['bank_id']);

        $result = $service->commit(
            $bank,
            $validated['statement_date'],
            $validated['rows']
        );

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Missing reconciliation rows processed successfully.',
        ], 201);
    }
}