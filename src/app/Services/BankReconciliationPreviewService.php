<?php

namespace App\Services;

use App\Models\Banks;
use App\Models\CharityTransactions;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BankReconciliationPreviewService
{
    public function preview(Banks $bank, string $statementDate, UploadedFile $file): array
    {
        [$statementStart, $statementEnd] = $this->statementDayWindow($statementDate);
        $config = $this->resolveBankConfig($bank);

        if (($config['file_type'] ?? 'spreadsheet') === 'csv') {
            $parsedRows = $this->parseCsvStatementRows($file, $config);
            $detectedStatementDate = $this->detectCsvStatementDate($parsedRows);
        } else {
            $spreadsheet = IOFactory::load($file->getRealPath());

            $sheet = $spreadsheet->getSheetByName($config['sheet_name']);
            if (! $sheet) {
                throw ValidationException::withMessages([
                    'file' => ["Sheet '{$config['sheet_name']}' was not found in the uploaded file."],
                ]);
            }

            $parsedRows = $this->parseStatementRows($sheet, $config);
            $detectedStatementDate = $this->detectStatementDate($sheet, $config);
        }

        if (empty($parsedRows)) {
            throw ValidationException::withMessages([
                'file' => ['No transaction rows were found in the uploaded file.'],
            ]);
        }

        $invalidRows = array_values(array_filter($parsedRows, fn ($row) => ! empty($row['errors'])));
        $validRows = array_values(array_filter($parsedRows, fn ($row) => empty($row['errors'])));

        $rowDatesDetected = collect($validRows)
            ->pluck('date')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Bank Dhofar special handling:
        // Trust the statement header date first because the Excel row date cells
        // can be internally inconsistent with the business date shown in the header.
        if (
            ($config['parser_code'] ?? null) === 'bank_dhofar_v1'
            && $detectedStatementDate
        ) {
            $validRows = array_map(function ($row) use ($detectedStatementDate) {
                $row['date'] = $detectedStatementDate;
                return $row;
            }, $validRows);

            $rowDatesDetected = [$detectedStatementDate];
        }

        if ($detectedStatementDate && $detectedStatementDate !== $statementDate) {
            $this->throwStatementDateMismatch(
                selectedDate: $statementDate,
                detectedStatementDate: $detectedStatementDate,
                rowDatesDetected: $rowDatesDetected,
                config: $config,
                reason: 'header_date_mismatch'
            );
        }

        if (($config['strict_row_date_validation'] ?? true) === true) {
            $unexpectedDates = collect($rowDatesDetected)
                ->reject(fn ($date) => $date === $statementDate)
                ->values()
                ->all();

            if (! empty($unexpectedDates)) {
                $this->throwStatementDateMismatch(
                    selectedDate: $statementDate,
                    detectedStatementDate: $detectedStatementDate,
                    rowDatesDetected: $rowDatesDetected,
                    config: $config,
                    reason: 'row_date_mismatch'
                );
            }
        }

        $dbTransactions = CharityTransactions::query()
            ->where('bank_transaction_id', $bank->id)
            ->where('created_at', '>=', $statementStart)
            ->where('created_at', '<', $statementEnd)
            ->whereIn('status', $this->successStatuses())
            ->orderBy('id')
            ->get([
                'id',
                'device_id',
                'terminal_id',
                'total_amount',
                'bank_response',
                'status',
                'created_at',
                'bank_transaction_id',
            ]);

        $dbByKey = [];
        $dbSnapshot = [];

        foreach ($dbTransactions as $tx) {
            $terminalId = $this->normalizeString($tx->terminal_id);
            $authCode = $this->normalizeString(data_get($tx->bank_response, $config['db_auth_json_path']));

            $entry = [
                'id' => (int) $tx->id,
                'device_id' => $tx->device_id ? (int) $tx->device_id : null,
                'created_at' => optional($tx->created_at)->format('Y-m-d H:i:s'),
                'terminal_id' => $terminalId,
                'auth_code' => $authCode,
                'total_amount' => round((float) $tx->total_amount, 3),
                'status' => $tx->status,
                'matchable' => ! empty($terminalId) && ! empty($authCode),
                'match_note' => (! empty($terminalId) && ! empty($authCode))
                    ? null
                    : 'Missing terminal_id or approval/auth code in DB transaction',
            ];

            $dbSnapshot[] = $entry;

            if ($entry['matchable']) {
                $dbByKey[$this->buildKey($terminalId, $authCode)][] = $entry;
            }
        }

        $matched = [];
        $missingInDb = [];
        $amountMismatches = [];
        $usedDbIds = [];

        foreach ($validRows as $row) {
            $key = $this->buildKey($row['terminal_id'], $row['auth_code']);

            $candidates = array_values(array_filter(
                $dbByKey[$key] ?? [],
                fn ($candidate) => ! isset($usedDbIds[$candidate['id']])
            ));

            if (empty($candidates)) {
                $missingInDb[] = [
                    'excel' => $row,
                    'reason' => 'No DB transaction found for selected bank/date using terminal_id + auth_code.',
                ];
                continue;
            }

            $dbMatch = $candidates[0];
            $usedDbIds[$dbMatch['id']] = true;

            $comparison = [
                'excel' => $row,
                'db' => $dbMatch,
            ];

            if ($this->sameMoney($row['gross_amount'], $dbMatch['total_amount'])) {
                $matched[] = $comparison;
            } else {
                $amountMismatches[] = $comparison + [
                    'amount_difference' => round($row['gross_amount'] - $dbMatch['total_amount'], 3),
                ];
            }
        }

        $dbOnly = array_values(array_filter(
            $dbSnapshot,
            fn ($row) => ! isset($usedDbIds[$row['id']])
        ));

        return [
            'bank' => [
                'id' => (int) $bank->id,
                'name' => $bank->name,
            ],

            'statement_date' => $statementDate,
            'detected_statement_date' => $detectedStatementDate,
            'row_dates_detected' => $rowDatesDetected,
            'parser' => $config['parser_code'],

            'summary' => [
                'excel_rows' => count($validRows),
                'excel_amount' => $this->sumExcelAmount($validRows),

                'db_rows' => $dbTransactions->count(),
                'db_amount' => round((float) $dbTransactions->sum('total_amount'), 3),

                'matched_rows' => count($matched),
                'matched_amount' => round(array_sum(array_map(
                    fn ($row) => (float) $row['excel']['gross_amount'],
                    $matched
                )), 3),

                'missing_in_db_rows' => count($missingInDb),
                'missing_in_db_amount' => round(array_sum(array_map(
                    fn ($row) => (float) $row['excel']['gross_amount'],
                    $missingInDb
                )), 3),

                'amount_mismatch_rows' => count($amountMismatches),
                'amount_mismatch_excel_amt' => round(array_sum(array_map(
                    fn ($row) => (float) $row['excel']['gross_amount'],
                    $amountMismatches
                )), 3),
                'amount_mismatch_db_amt' => round(array_sum(array_map(
                    fn ($row) => (float) $row['db']['total_amount'],
                    $amountMismatches
                )), 3),

                'db_only_rows' => count($dbOnly),
                'db_only_amount' => round(array_sum(array_map(
                    fn ($row) => (float) $row['total_amount'],
                    $dbOnly
                )), 3),

                'invalid_rows' => count($invalidRows),
            ],

            'matched' => array_values($matched),
            'missing_in_db' => array_values($missingInDb),
            'amount_mismatches' => array_values($amountMismatches),
            'db_only' => array_values($dbOnly),
            'invalid_rows' => array_values($invalidRows),
        ];
    }

    private function resolveBankConfig(Banks $bank): array
    {
        $bankName = Str::lower(trim((string) ($bank->short_name ?: $bank->name)));

        if ((int) $bank->id === 2 || str_contains($bankName, 'dhofar')) {
            return [
                'parser_code' => 'bank_dhofar_v1',
                'file_type' => 'spreadsheet',
                'sheet_name' => 'Table 1',
                'db_auth_json_path' => 'receiptResponse.approvalCode',
                'strict_row_date_validation' => false,
                'statement_header' => [
                    'cell' => 'E4',
                    'formats' => ['d/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y', 'Y-m-d'],
                ],
                'columns' => [
                    'date' => 'DATE',
                    'terminal_id' => 'TERMINAL ID',
                    'auth_code' => 'AUTHO CODE',
                    'gross_amount' => 'GROSS AMOUNT',
                    'card_no' => 'CARD NO',
                ],
            ];
        }

        if ((int) $bank->id === 1 || str_contains($bankName, 'oman arab') || str_contains($bankName, 'oab')) {
            return [
                'parser_code' => 'bank_oab_csv_v1',
                'file_type' => 'csv',
                'db_auth_json_path' => 'statusCode',
                'strict_row_date_validation' => true,
                'columns' => [
                    'transaction_date' => 'TRANSACTION_DATE',
                    'terminal_id' => 'TERMINAL_ID',
                    'branch_id' => 'BRANCH_ID',
                    'card_no' => 'CARD_NUMBER',
                    'card_type' => 'CARD_TYPE',
                    'transaction_type' => 'TRANSACTION_TYPE',
                    'transaction_reference' => 'TRANSACTION_REFERENCE',
                    'rrn' => 'RETRIEVAL_REF_NUMBER',
                    'auth_code' => 'AUTH_CODE',
                    'gross_amount' => 'TRANSACTION_AMOUNT',
                    'discount_amount' => 'DISCOUNTRATE_AMOUNT',
                    'vat_amount' => 'VAT_AMOUNT',
                    'net_amount' => 'NET_AMOUNT',
                    'related_reference' => 'RELATED_REFERENCE',
                    'date' => 'SETTLEMENTDATE',
                ],
            ];
        }

        throw ValidationException::withMessages([
            'bank_id' => ['Preview parser is not configured for the selected bank yet.'],
        ]);
    }

    private function parseStatementRows(Worksheet $sheet, array $config): array
    {
        [$headerRow, $columnMap] = $this->locateHeaderRow($sheet, $config['columns']);

        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $dateCell = $sheet->getCell([$columnMap['date'], $row]);
            $terminalCell = $sheet->getCell([$columnMap['terminal_id'], $row]);
            $authCell = $sheet->getCell([$columnMap['auth_code'], $row]);
            $amountCell = $sheet->getCell([$columnMap['gross_amount'], $row]);
            $cardCell = isset($columnMap['card_no']) ? $sheet->getCell([$columnMap['card_no'], $row]) : null;

            $rawDate = $dateCell->getValue();
            $rawTerminal = $terminalCell->getFormattedValue();
            $rawAuth = $authCell->getFormattedValue();
            $rawAmount = $amountCell->getFormattedValue();
            $rawCardNo = $cardCell ? $cardCell->getFormattedValue() : null;

            if ($this->isBlankRow([$rawDate, $rawTerminal, $rawAuth, $rawAmount, $rawCardNo])) {
                continue;
            }

            $normalizedDate = $this->normalizeDateValue($rawDate, $dateCell->getFormattedValue());
            $normalizedTid = $this->normalizeString($rawTerminal);
            $normalizedAuth = $this->normalizeString($rawAuth);
            $normalizedAmount = $this->normalizeAmount($amountCell->getValue(), $rawAmount);
            $normalizedCardNo = $this->normalizeCardNumber($rawCardNo);

            $errors = [];

            if (! $normalizedDate) {
                $errors[] = 'Invalid DATE';
            }

            if (! $normalizedTid) {
                $errors[] = 'Missing TERMINAL ID';
            }

            if (! $normalizedAuth) {
                $errors[] = 'Missing AUTHO CODE';
            }

            if ($normalizedAmount === null) {
                $errors[] = 'Invalid GROSS AMOUNT';
            }

            $rows[] = [
                'row_number' => $row,
                'date' => $normalizedDate,
                'terminal_id' => $normalizedTid,
                'auth_code' => $normalizedAuth,
                'gross_amount' => $normalizedAmount,
                'card_no' => $normalizedCardNo,
                'errors' => $errors,
            ];
        }

        return $rows;
    }

    private function parseCsvStatementRows(UploadedFile $file, array $config): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw ValidationException::withMessages([
                'file' => ['Could not open the uploaded CSV file.'],
            ]);
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false || $headerRow === null) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => ['The uploaded CSV file is empty.'],
            ]);
        }

        $headerMap = $this->locateCsvHeaderMap($headerRow, $config['columns']);
        $rows = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($data === [null] || $data === null) {
                continue;
            }

            $rawSettlementDate = $this->csvValue($data, $headerMap['date']);
            $rawTransactionDate = $this->csvValue($data, $headerMap['transaction_date']);
            $rawTerminal = $this->csvValue($data, $headerMap['terminal_id']);
            $rawAuth = $this->csvValue($data, $headerMap['auth_code']);
            $rawAmount = $this->csvValue($data, $headerMap['gross_amount']);
            $rawCardNo = $this->csvValue($data, $headerMap['card_no']);
            $rawRrn = $this->csvValue($data, $headerMap['rrn']);
            $rawCardType = $this->csvValue($data, $headerMap['card_type']);
            $rawTransactionType = $this->csvValue($data, $headerMap['transaction_type']);
            $rawTransactionReference = $this->csvValue($data, $headerMap['transaction_reference']);
            $rawBranchId = $this->csvValue($data, $headerMap['branch_id']);
            $rawDiscountAmount = $this->csvValue($data, $headerMap['discount_amount']);
            $rawVatAmount = $this->csvValue($data, $headerMap['vat_amount']);
            $rawNetAmount = $this->csvValue($data, $headerMap['net_amount']);
            $rawRelatedReference = $this->csvValue($data, $headerMap['related_reference']);

            if ($this->isBlankRow([
                $rawSettlementDate,
                $rawTransactionDate,
                $rawTerminal,
                $rawAuth,
                $rawAmount,
                $rawCardNo,
                $rawRrn,
            ])) {
                continue;
            }

            $normalizedSettlementDate = $this->normalizeDateValue(
                $rawSettlementDate,
                $rawSettlementDate,
                ['n/j/Y', 'j/n/Y', 'm/d/Y', 'd/m/Y', 'Y-m-d']
            );

            $normalizedTid = $this->normalizeString($rawTerminal);
            $normalizedAuth = $this->normalizeString($rawAuth);
            $normalizedAmount = $this->normalizeAmount($rawAmount, $rawAmount);
            $normalizedCardNo = $this->normalizeCardNumber($rawCardNo);
            $normalizedRrn = $this->normalizeReference($rawRrn);
            $normalizedTransactionDate = $this->normalizeDateTimeString($rawTransactionDate);
            $normalizedCardType = $this->normalizeText($rawCardType);
            $normalizedTransactionType = $this->normalizeText($rawTransactionType);
            $normalizedTransactionReference = $this->normalizeText($rawTransactionReference);
            $normalizedBranchId = $this->normalizeText($rawBranchId);
            $normalizedRelatedReference = $this->normalizeText($rawRelatedReference);
            $normalizedDiscountAmount = $this->normalizeAmount($rawDiscountAmount, $rawDiscountAmount);
            $normalizedVatAmount = $this->normalizeAmount($rawVatAmount, $rawVatAmount);
            $normalizedNetAmount = $this->normalizeAmount($rawNetAmount, $rawNetAmount);

            $errors = [];

            if (! $normalizedSettlementDate) {
                $errors[] = 'Invalid SETTLEMENTDATE';
            }

            if (! $normalizedTid) {
                $errors[] = 'Missing TERMINAL_ID';
            }

            if (! $normalizedAuth) {
                $errors[] = 'Missing AUTH_CODE';
            }

            if ($normalizedAmount === null) {
                $errors[] = 'Invalid TRANSACTION_AMOUNT';
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'date' => $normalizedSettlementDate,
                'terminal_id' => $normalizedTid,
                'auth_code' => $normalizedAuth,
                'gross_amount' => $normalizedAmount,
                'card_no' => $normalizedCardNo,
                'rrn' => $normalizedRrn,
                'branch_id' => $normalizedBranchId,
                'card_type' => $normalizedCardType,
                'transaction_type' => $normalizedTransactionType,
                'transaction_reference' => $normalizedTransactionReference,
                'related_reference' => $normalizedRelatedReference,
                'transaction_date' => $normalizedTransactionDate,
                'settlement_date' => $normalizedSettlementDate,
                'discount_amount' => $normalizedDiscountAmount,
                'vat_amount' => $normalizedVatAmount,
                'net_amount' => $normalizedNetAmount,
                'errors' => $errors,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function detectCsvStatementDate(array $rows): ?string
    {
        $dates = collect($rows)
            ->pluck('date')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return count($dates) === 1 ? $dates[0] : null;
    }

    private function detectStatementDate(Worksheet $sheet, array $config): ?string
    {
        $headerConfig = $config['statement_header'] ?? null;

        if (! is_array($headerConfig) || empty($headerConfig['cell'])) {
            return null;
        }

        $cell = $sheet->getCell($headerConfig['cell']);
        $rawValue = $cell->getValue();
        $formattedValue = trim((string) $cell->getFormattedValue());
        $formats = $headerConfig['formats'] ?? ['d/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y', 'Y-m-d'];

        if (preg_match('/(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4})/', $formattedValue, $matches)) {
            $fromText = $this->normalizeDateValue($matches[1], $matches[1], $formats);
            if ($fromText) {
                return $fromText;
            }
        }

        $dateFromRaw = $this->normalizeDateValue($rawValue, $formattedValue, $formats);
        if ($dateFromRaw) {
            return $dateFromRaw;
        }

        return null;
    }

    private function locateHeaderRow(Worksheet $sheet, array $requiredColumns): array
    {
        $highestRow = min(30, $sheet->getHighestDataRow());

        $normalizedTargets = [];
        foreach ($requiredColumns as $key => $label) {
            $normalizedTargets[$key] = $this->normalizeHeader($label);
        }

        for ($row = 1; $row <= $highestRow; $row++) {
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn($row));
            $found = [];

            for ($col = 1; $col <= $highestColumn; $col++) {
                $value = $this->normalizeHeader((string) $sheet->getCell([$col, $row])->getFormattedValue());

                foreach ($normalizedTargets as $key => $target) {
                    if ($value === $target) {
                        $found[$key] = $col;
                    }
                }
            }

            if (count($found) === count($normalizedTargets)) {
                return [$row, $found];
            }
        }

        throw ValidationException::withMessages([
            'file' => ['Could not find the required header row in the uploaded file.'],
        ]);
    }

    private function locateCsvHeaderMap(array $headerRow, array $requiredColumns): array
    {
        $normalizedHeaders = [];
        foreach ($headerRow as $index => $label) {
            $normalizedHeaders[$index] = $this->normalizeHeader((string) $label);
        }

        $found = [];
        foreach ($requiredColumns as $key => $label) {
            $target = $this->normalizeHeader($label);
            $index = array_search($target, $normalizedHeaders, true);

            if ($index === false) {
                throw ValidationException::withMessages([
                    'file' => ["Required CSV column '{$label}' was not found in the uploaded file."],
                ]);
            }

            $found[$key] = $index;
        }

        return $found;
    }

    private function csvValue(array $row, int $index): ?string
    {
        $value = $row[$index] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeHeader(?string $value): string
    {
        $value = (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value);
        $value = Str::upper($value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value ?? '';
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

    private function normalizeAmount($rawValue, $formattedValue): ?float
    {
        if (is_numeric($rawValue)) {
            return round((float) $rawValue, 3);
        }

        $value = trim((string) $formattedValue);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^0-9\.\-]/', '', $value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 3);
    }

    private function normalizeDateValue($rawValue, $formattedValue, array $preferredFormats = []): ?string
    {
        try {
            if ($rawValue instanceof \DateTimeInterface) {
                return Carbon::instance($rawValue)->toDateString();
            }

            if (is_numeric($rawValue)) {
                return Carbon::instance(
                    ExcelDate::excelToDateTimeObject((float) $rawValue)
                )->toDateString();
            }

            $formatted = trim((string) $formattedValue);

            if ($formatted === '') {
                return null;
            }

            $formats = array_values(array_unique(array_merge(
                $preferredFormats,
                [
                    'Y-m-d',
                    'd/m/Y',
                    'm/d/Y',
                    'd-m-Y',
                    'm-d-Y',
                    'd.m.Y',
                    'n/j/Y',
                    'j/n/Y',
                ]
            )));

            foreach ($formats as $format) {
                try {
                    return Carbon::createFromFormat($format, $formatted)->toDateString();
                } catch (\Throwable $e) {
                    // keep trying
                }
            }

            return Carbon::parse($formatted)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeDateTimeString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'n/j/Y G:i',
            'n/j/Y H:i',
            'j/n/Y G:i',
            'j/n/Y H:i',
            'm/d/Y G:i',
            'm/d/Y H:i',
            'd/m/Y G:i',
            'd/m/Y H:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateTimeString();
            } catch (\Throwable $e) {
                // keep trying
            }
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function buildKey(?string $terminalId, ?string $authCode): string
    {
        return ($terminalId ?? '') . '|' . ($authCode ?? '');
    }

    private function sameMoney(?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) < 0.0005;
    }

    private function sumExcelAmount(array $rows): float
    {
        return round(array_sum(array_map(
            fn ($row) => (float) ($row['gross_amount'] ?? 0),
            $rows
        )), 3);
    }

    private function throwStatementDateMismatch(
        string $selectedDate,
        ?string $detectedStatementDate,
        array $rowDatesDetected,
        array $config,
        string $reason = 'date_mismatch'
    ): void {
        $rowDatesDetected = array_values(array_unique(array_filter($rowDatesDetected)));
        $statementHeaderCell = data_get($config, 'statement_header.cell');

        $suggestedDate = $detectedStatementDate
            ?: ($rowDatesDetected[0] ?? null);

        $messageParts = [
            "Selected date: {$selectedDate}",
        ];

        if ($detectedStatementDate) {
            $messageParts[] = "Detected statement date: {$detectedStatementDate}";
        }

        if (! empty($rowDatesDetected)) {
            $messageParts[] = 'Detected row dates: ' . implode(', ', $rowDatesDetected);
        }

        if ($statementHeaderCell) {
            $messageParts[] = "Statement header cell: {$statementHeaderCell}";
        }

        if ($suggestedDate) {
            $messageParts[] = "Suggested date to use: {$suggestedDate}";
        }

        $message = 'The selected date does not match the uploaded bank statement. ' . implode(' | ', $messageParts);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => $message,
                'errors' => [
                    'statement_date' => [
                        'The selected date does not match the uploaded bank statement.',
                        "Selected date: {$selectedDate}",
                        $detectedStatementDate
                            ? "Detected statement date: {$detectedStatementDate}"
                            : 'Detected statement date: not found',
                        ! empty($rowDatesDetected)
                            ? 'Detected row dates: ' . implode(', ', $rowDatesDetected)
                            : 'Detected row dates: not found',
                        $statementHeaderCell
                            ? "Statement header cell: {$statementHeaderCell}"
                            : 'Statement header cell: not configured',
                        $suggestedDate
                            ? "Suggested date to use: {$suggestedDate}"
                            : 'Suggested date to use: not available',
                    ],
                ],
                'meta' => [
                    'reason' => $reason,
                    'selected_date' => $selectedDate,
                    'detected_statement_date' => $detectedStatementDate,
                    'row_dates_detected' => $rowDatesDetected,
                    'statement_header_cell' => $statementHeaderCell,
                    'suggested_date' => $suggestedDate,
                ],
            ], 422)
        );
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
