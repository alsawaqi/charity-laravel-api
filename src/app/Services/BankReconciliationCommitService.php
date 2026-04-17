<?php

namespace App\Services;

use App\Models\Banks;
use App\Models\CharityTransactions;
use App\Models\CharityTransactionShare;
use App\Models\CommissionProfilesShares;
use App\Models\Devices;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BankReconciliationCommitService
{
    public function commit(Banks $bank, string $statementDate, array $rows): array
    {
        [$statementStart, $statementEnd] = $this->statementDayWindow($statementDate);

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'rows' => ['There are no rows to import.'],
            ]);
        }

        $config = $this->resolveBankConfig($bank);

        $normalizedRows = array_map(function ($row) use ($statementDate) {
            return [
                'row_number' => $row['row_number'] ?? null,
                'date' => $row['date'] ?? $statementDate,
                'transaction_date' => $row['transaction_date'] ?? null,
                'settlement_date' => $row['settlement_date'] ?? ($row['date'] ?? $statementDate),
                'terminal_id' => $this->normalizeString($row['terminal_id'] ?? null),
                'auth_code' => $this->normalizeString($row['auth_code'] ?? null),
                'gross_amount' => isset($row['gross_amount']) ? round((float) $row['gross_amount'], 3) : null,
                'card_no' => $this->normalizeCardNumber($row['card_no'] ?? null),
                'rrn' => $this->normalizeReference($row['rrn'] ?? null),
                'branch_id' => $this->normalizeText($row['branch_id'] ?? null),
                'card_type' => $this->normalizeText($row['card_type'] ?? null),
                'transaction_type' => $this->normalizeText($row['transaction_type'] ?? null),
                'transaction_reference' => $this->normalizeText($row['transaction_reference'] ?? null),
                'related_reference' => $this->normalizeText($row['related_reference'] ?? null),
                'discount_amount' => isset($row['discount_amount']) && $row['discount_amount'] !== null && $row['discount_amount'] !== ''
                    ? round((float) $row['discount_amount'], 3)
                    : null,
                'vat_amount' => isset($row['vat_amount']) && $row['vat_amount'] !== null && $row['vat_amount'] !== ''
                    ? round((float) $row['vat_amount'], 3)
                    : null,
                'net_amount' => isset($row['net_amount']) && $row['net_amount'] !== null && $row['net_amount'] !== ''
                    ? round((float) $row['net_amount'], 3)
                    : null,
            ];
        }, $rows);

        $terminalIds = collect($normalizedRows)
            ->pluck('terminal_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $devices = Devices::with('charityLocation')
            ->where('bank_id', $bank->id)
            ->whereIn('terminal_id', $terminalIds)
            ->get()
            ->keyBy(fn ($device) => $this->normalizeString($device->terminal_id));

        $existingTransactions = CharityTransactions::query()
            ->where('bank_transaction_id', $bank->id)
            ->where('created_at', '>=', $statementStart)
            ->where('created_at', '<', $statementEnd)
            ->whereIn('status', $this->successStatuses())
            ->get([
                'id',
                'terminal_id',
                'bank_response',
                'total_amount',
                'created_at',
            ]);

        $existingByKey = [];
        foreach ($existingTransactions as $tx) {
            $terminalId = $this->normalizeString($tx->terminal_id);
            $authCode = $this->normalizeString(data_get($tx->bank_response, $config['db_auth_json_path']));

            if ($terminalId && $authCode) {
                $existingByKey[$this->buildKey($terminalId, $authCode)] = [
                    'id' => (int) $tx->id,
                    'total_amount' => round((float) $tx->total_amount, 3),
                    'created_at' => optional($tx->created_at)->format('Y-m-d H:i:s'),
                ];
            }
        }

        $inserted = [];
        $skippedExisting = [];
        $errors = [];

        DB::transaction(function () use (
            $normalizedRows,
            $devices,
            $existingByKey,
            $bank,
            $statementDate,
            $statementStart,
            &$inserted,
            &$skippedExisting,
            &$errors
        ) {
            foreach ($normalizedRows as $row) {
                if (! $row['terminal_id']) {
                    $errors[] = [
                        'row_number' => $row['row_number'],
                        'reason' => 'Missing terminal_id.',
                    ];
                    continue;
                }

                if (! $row['auth_code']) {
                    $errors[] = [
                        'row_number' => $row['row_number'],
                        'terminal_id' => $row['terminal_id'],
                        'reason' => 'Missing auth_code.',
                    ];
                    continue;
                }

                if ($row['gross_amount'] === null) {
                    $errors[] = [
                        'row_number' => $row['row_number'],
                        'terminal_id' => $row['terminal_id'],
                        'auth_code' => $row['auth_code'],
                        'reason' => 'Missing gross_amount.',
                    ];
                    continue;
                }

                $device = $devices->get($row['terminal_id']);

                if (! $device) {
                    $errors[] = [
                        'row_number' => $row['row_number'],
                        'terminal_id' => $row['terminal_id'],
                        'auth_code' => $row['auth_code'],
                        'reason' => 'No device found for this terminal_id and bank.',
                    ];
                    continue;
                }

                if (! $device->commission_profile_id) {
                    $errors[] = [
                        'row_number' => $row['row_number'],
                        'terminal_id' => $row['terminal_id'],
                        'auth_code' => $row['auth_code'],
                        'reason' => 'Device has no commission profile assigned.',
                    ];
                    continue;
                }

                $key = $this->buildKey($row['terminal_id'], $row['auth_code']);

                if (isset($existingByKey[$key])) {
                    $skippedExisting[] = [
                        'row_number' => $row['row_number'],
                        'terminal_id' => $row['terminal_id'],
                        'auth_code' => $row['auth_code'],
                        'gross_amount' => $row['gross_amount'],
                        'existing_transaction_id' => $existingByKey[$key]['id'],
                        'reason' => 'Transaction already exists for selected bank/date using terminal_id + auth_code.',
                    ];
                    continue;
                }

                $organizationId = optional($device->charityLocation)->organization_id;

                $receipt = $this->buildReceiptFromImportedRow(
                    bank: $bank,
                    device: $device,
                    row: $row,
                    statementDate: $statementDate
                );

                $charity = CharityTransactions::create([
                    'device_id' => $device->id,
                    'commission_profile_id' => $device->commission_profile_id,
                    'total_amount' => $row['gross_amount'],
                    'bank_response' => $receipt,
                    'bank_transaction_id' => $bank->id,
                    'status' => 'success',

                    'country_id' => $device->country_id,
                    'region_id' => $device->region_id,
                    'city_id' => $device->city_id,
                    'charity_location_id' => $device->charity_location_id,
                    'district_id' => $device->district_id,
                    'company_id' => $device->companies_id,
                    'main_location_id' => $device->main_location_id,
                    'organization_id' => $organizationId,

                    'latitude' => 0.00,
                    'longitude' => 0.00,
                    'terminal_id' => $device->terminal_id,

                    'created_at' => $statementStart->copy(),
                    'updated_at' => now(),
                ]);

                $shares = CommissionProfilesShares::where('commission_profile_id', $device->commission_profile_id)->get();

                foreach ($shares as $share) {
                    $percentage = (float) $share->percentage;
                    $shareAmount = round($row['gross_amount'] * $percentage / 100, 3);

                    CharityTransactionShare::create([
                        'charity_transaction_id' => $charity->id,
                        'commission_profile_share_id' => $share->id,
                        'amount' => $shareAmount,
                    ]);
                }

                $inserted[] = [
                    'row_number' => $row['row_number'],
                    'charity_transaction_id' => $charity->id,
                    'terminal_id' => $device->terminal_id,
                    'auth_code' => $row['auth_code'],
                    'gross_amount' => $row['gross_amount'],
                    'created_at' => $charity->created_at?->format('Y-m-d H:i:s'),
                ];

                $existingByKey[$key] = [
                    'id' => $charity->id,
                    'total_amount' => $row['gross_amount'],
                    'created_at' => $charity->created_at?->format('Y-m-d H:i:s'),
                ];
            }
        });

        return [
            'bank' => [
                'id' => (int) $bank->id,
                'name' => $bank->name,
            ],
            'statement_date' => $statementDate,
            'summary' => [
                'requested_rows' => count($normalizedRows),
                'inserted_rows' => count($inserted),
                'inserted_amount' => round(array_sum(array_map(fn ($r) => (float) $r['gross_amount'], $inserted)), 3),
                'skipped_existing_rows' => count($skippedExisting),
                'error_rows' => count($errors),
            ],
            'inserted' => $inserted,
            'skipped_existing' => $skippedExisting,
            'errors' => $errors,
        ];
    }

    private function resolveBankConfig(Banks $bank): array
    {
        $bankName = Str::lower(trim((string) ($bank->short_name ?: $bank->name)));

        if ((int) $bank->id === 2 || str_contains($bankName, 'dhofar')) {
            return [
                'parser_code' => 'bank_dhofar_v1',
                'db_auth_json_path' => 'receiptResponse.approvalCode',
                'currency' => 'OMR',
                'acquirer_name' => 'BANKDHOFARACQ',
                'acquirer_logo' => 'BANKDHOFARACQ',
            ];
        }

        if ((int) $bank->id === 1 || str_contains($bankName, 'oman arab') || str_contains($bankName, 'oab')) {
            return [
                'parser_code' => 'bank_oab_csv_v1',
                'db_auth_json_path' => 'statusCode',
                'currency' => 'OMR',
            ];
        }

        throw ValidationException::withMessages([
            'bank_id' => ['Commit import is not configured for the selected bank yet.'],
        ]);
    }

    private function buildReceiptFromImportedRow(Banks $bank, Devices $device, array $row, string $statementDate): array
    {
        $config = $this->resolveBankConfig($bank);

        if (($config['parser_code'] ?? null) === 'bank_oab_csv_v1') {
            return $this->buildOabReceiptFromCsvRow($bank, $device, $row, $statementDate);
        }

        return $this->buildDhofarReceiptFromSpreadsheetRow($bank, $device, $row, $statementDate, $config);
    }

    private function buildDhofarReceiptFromSpreadsheetRow(Banks $bank, Devices $device, array $row, string $statementDate, array $config): array
    {
        $amount = number_format((float) $row['gross_amount'], 3, '.', '');
        $date = Carbon::parse($statementDate)->format('d-m-Y');

        return [
            'stage' => 'payment',
            'status' => 'success',
            'resultCode' => 102,
            'receiptResponse' => [
                'ac' => 'NA',
                'cid' => '80',
                'tsi' => '0000',
                'tvr' => '0000000000',
                '9F06' => 'NA',
                '9F11' => 'NA',
                '9F12' => 'NA',
                'date' => $date,
                'stan' => 'NA',
                'time' => '00:00:00',
                'action' => 'reconciliation_import',
                'amount' => $amount,
                'result' => 'success',
                'message' => 'Imported from bank reconciliation',
                'address1' => 'NA',
                'address2' => 'NA',
                'appLabel' => 'NA',
                'cardType' => 'NA',
                'currency' => $config['currency'],
                'deviceId' => $device->id,
                'latitude' => '0.00',
                'firstName' => $device->terminal_id,
                'issuerCTI' => 'NA',
                'longitude' => '0.00',
                'sessionId' => 'NA',
                'tipAmount' => '0.00',
                'tipOption' => '0',
                'appPreName' => 'NA',
                'appVersion' => 'bank_reconciliation_import',
                'billNumber' => 'Charity Donation',
                'cardNumber' => $row['card_no'] ?? 'NA',
                'expiryDate' => 'NA',
                'merchantId' => 'NA',
                'statusCode' => '2',
                'terminalId' => $device->terminal_id,
                'batchNumber' => '000001',
                'productInfo' => '{"info":"Imported from bank reconciliation","sku":"NA"}',
                'acquirerLogo' => $config['acquirer_logo'],
                'acquirerName' => $config['acquirer_name'],
                'approvalCode' => $row['auth_code'],
                'businessName' => 'Imported from bank reconciliation',
                'fullCardType' => 'NA',
                'responseCode' => '00',
                'applicationId' => 'NA',
                'invoiceNumber' => 'NA',
                'isPinVerified' => false,
                'transactionId' => null,
                'issuerBankName' => 'NA',
                'tgTransactionID' => null,
                'transactionMode' => 'BANK RECONCILIATION IMPORT',
                'transactionType' => 1,
                'typeTransaction' => 'sale',
                'custMidVisibility' => 1,
                'custTidVisibility' => 1,
                'refTransactionType' => '0',
                'creditDebitCardType' => 'NA',
                'custMaskedCardNumber' => $row['card_no'] ?? 'NA',
                'merchantMidVisibility' => 1,
                'merchantTidVisibility' => 1,
                'processingMerchantCode' => null,
                'creditDebitCardTypeName' => null,
                'receivingInstitutionCode' => 'NA',
                'retrievalReferenceNumber' => 'NA',
            ],
            'paymentDescription' => 'Success',
            'paymentResponseCode' => '0',
            'reconciliationMeta' => [
                'source' => 'bank_reconciliation_excel',
                'statement_date' => $statementDate,
                'excel_row_number' => $row['row_number'],
                'imported_at' => now()->toDateTimeString(),
                'bank_id' => $bank->id,
                'terminal_id' => $device->terminal_id,
                'auth_code' => $row['auth_code'],
            ],
        ];
    }

    private function buildOabReceiptFromCsvRow(Banks $bank, Devices $device, array $row, string $statementDate): array
    {
        [$binId, $panId] = $this->extractCardTokens($row['card_no'] ?? null);

        return [
            'reason' => 'SUCCESS',
            'message' => 'Pay Success',
            'statusCode' => $row['auth_code'],
            'sessionData' => [
                'mid' => 'NA',
                'rrn' => $row['rrn'] ?? 'NA',
                'tid' => $device->terminal_id,
                'meta' => '{}',
                'binId' => $binId ?? 'NA',
                'panId' => $panId ?? 'NA',
                'issuer' => $row['card_type'] ?? 'NA',
                'merchantName' => optional($device->charityLocation)->name ?: 'Imported from bank reconciliation',
            ],
            'isSuccessful' => true,
            'reconciliationMeta' => [
                'source' => 'bank_reconciliation_csv',
                'statement_date' => $statementDate,
                'excel_row_number' => $row['row_number'],
                'imported_at' => now()->toDateTimeString(),
                'bank_id' => $bank->id,
                'terminal_id' => $device->terminal_id,
                'auth_code' => $row['auth_code'],
                'transaction_date' => $row['transaction_date'] ?? null,
                'settlement_date' => $row['settlement_date'] ?? $statementDate,
                'transaction_reference' => $row['transaction_reference'] ?? null,
                'related_reference' => $row['related_reference'] ?? null,
                'transaction_type' => $row['transaction_type'] ?? null,
                'branch_id' => $row['branch_id'] ?? null,
                'card_no' => $row['card_no'] ?? null,
                'discount_amount' => $row['discount_amount'] ?? null,
                'vat_amount' => $row['vat_amount'] ?? null,
                'net_amount' => $row['net_amount'] ?? null,
            ],
        ];
    }

    private function extractCardTokens(?string $cardNumber): array
    {
        $cardNumber = $this->normalizeCardNumber($cardNumber);

        if (! $cardNumber || strlen($cardNumber) < 10) {
            return [null, null];
        }

        return [
            substr($cardNumber, 0, 6),
            substr($cardNumber, -4),
        ];
    }

    private function normalizeString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        $value = preg_replace('/\s+/', '', $value);
        $value = Str::upper($value);

        return $value === '' ? null : $value;
    }

    private function normalizeText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeReference($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        $value = preg_replace('/\.0$/', '', $value);
        $value = preg_replace('/\s+/', '', $value);

        return $value === '' ? null : $value;
    }

    private function normalizeCardNumber($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        $value = preg_replace('/\s+/', '', $value);

        return $value === '' ? null : $value;
    }

    private function buildKey(?string $terminalId, ?string $authCode): string
    {
        return ($terminalId ?? '') . '|' . ($authCode ?? '');
    }

    private function successStatuses(): array
    {
        return ['success', 'successful'];
    }

    private function statementDayWindow(string $statementDate): array
    {
        $timezone = config('app.timezone', 'Asia/Muscat');
        $start = Carbon::createFromFormat('Y-m-d', $statementDate, $timezone)->startOfDay();
        $end = $start->copy()->addDay();

        return [$start, $end];
    }
}
