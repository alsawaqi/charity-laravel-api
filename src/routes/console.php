<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:sync-postgres-sequences {--dry-run : Show the sequence updates without applying them}', function () {
    $connection = DB::connection();

    if ($connection->getDriverName() !== 'pgsql') {
        $this->error('This command only supports PostgreSQL connections.');

        return self::FAILURE;
    }

    $sequences = collect(DB::select(<<<'SQL'
        SELECT
            table_name,
            column_name,
            pg_get_serial_sequence(format('%I.%I', table_schema, table_name), column_name) AS sequence_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND column_default LIKE 'nextval(%'
        ORDER BY table_name, ordinal_position
    SQL
    ))->filter(fn ($row) => !empty($row->sequence_name))->values();

    if ($sequences->isEmpty()) {
        $this->info('No PostgreSQL sequences found in the current schema.');

        return self::SUCCESS;
    }

    $rows = [];
    $updated = 0;
    $dryRun = (bool) $this->option('dry-run');

    foreach ($sequences as $sequence) {
        $maxValue = DB::table($sequence->table_name)->max($sequence->column_name);
        $sequenceValue = $maxValue === null ? 1 : (int) $maxValue;
        $isCalled = $maxValue === null ? 'false' : 'true';

        $rows[] = [
            $sequence->table_name,
            $sequence->column_name,
            $sequence->sequence_name,
            $maxValue ?? 'empty',
            $dryRun ? 'pending' : 'synced',
        ];

        if ($dryRun) {
            continue;
        }

        $escapedSequenceName = str_replace("'", "''", $sequence->sequence_name);

        DB::statement(sprintf(
            "SELECT setval('%s', %d, %s)",
            $escapedSequenceName,
            $sequenceValue,
            $isCalled
        ));

        $updated++;
    }

    $this->table(['Table', 'Column', 'Sequence', 'Max ID', 'Status'], $rows);

    if ($dryRun) {
        $this->info(sprintf('Dry run complete. %d sequence(s) would be checked.', $rows ? count($rows) : 0));

        return self::SUCCESS;
    }

    $this->info(sprintf('Synced %d PostgreSQL sequence(s).', $updated));

    return self::SUCCESS;
})->purpose('Sync PostgreSQL sequences with the current maximum primary keys');

Artisan::command('bank-devices:encrypt-passwords {--dry-run : Show what would be encrypted without updating the database}', function () {
    $devices = DB::table('devices')
        ->select('id', 'device_code', 'bank_username', 'bank_password')
        ->whereNotNull('bank_password')
        ->where('bank_password', '<>', '')
        ->orderBy('id')
        ->get();

    if ($devices->isEmpty()) {
        $this->info('No bank device passwords found to inspect.');

        return self::SUCCESS;
    }

    $rows = [];
    $updated = 0;
    $skipped = 0;
    $dryRun = (bool) $this->option('dry-run');

    foreach ($devices as $device) {
        try {
            Crypt::decryptString($device->bank_password);

            $rows[] = [
                $device->id,
                $device->device_code,
                $device->bank_username,
                'already_encrypted',
            ];

            $skipped++;
            continue;
        } catch (Throwable $exception) {
            // Not decryptable means it is legacy plain text and should be encrypted.
        }

        if ($dryRun) {
            $rows[] = [
                $device->id,
                $device->device_code,
                $device->bank_username,
                'would_encrypt',
            ];

            $skipped++;
            continue;
        }

        try {
            DB::table('devices')
                ->where('id', $device->id)
                ->update([
                    'bank_password' => Crypt::encryptString($device->bank_password),
                    'updated_at' => now(),
                ]);

            $rows[] = [
                $device->id,
                $device->device_code,
                $device->bank_username,
                'encrypted',
            ];

            $updated++;
        } catch (Throwable $exception) {
            $rows[] = [
                $device->id,
                $device->device_code,
                $device->bank_username,
                'failed',
            ];

            $skipped++;
        }
    }

    $this->table(['ID', 'Device Code', 'Username', 'Status'], $rows);
    $this->info(sprintf('Encrypted %d password(s). Skipped %d.', $updated, $skipped));

    return self::SUCCESS;
})->purpose('Encrypt existing plain-text bank device passwords in-place');
