<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\DB;
 

Route::get('/', function () {
    return response()->json(['message' => 'Welcome to the homepage!']);
});




Route::get('/admin/fix-devices-main-location', function () {
    $affected = DB::update("
        UPDATE devices AS d
        SET main_location_id = cl.main_location_id
        FROM charity_locations AS cl
        WHERE cl.id = d.charity_location_id
    ");

    return response()->json([
        'success' => true,
        'message' => "Devices updated successfully. Rows affected: {$affected}",
    ]);
});




Route::get('/admin/fix-devices-companies', function () {
    $affected = DB::update("
        UPDATE devices AS d
        SET companies_id = ml.company_id
        FROM main_locations AS ml
        WHERE ml.id = d.main_location_id
    ");

    return response()->json([
        'success' => true,
        'message' => "Devices updated successfully. Rows affected: {$affected}",
    ]);
});



Route::get('/admin/fix-devices-terminal-id', function () {
    $affected = DB::affectingStatement("
        WITH first_tid AS (
            SELECT DISTINCT ON (ct.device_id)
                ct.device_id,
                (ct.bank_response::jsonb #>> '{sessionData,tid}') AS tid
            FROM charity_transactions ct
            WHERE lower(ct.status) = 'success'
              AND ct.bank_transaction_id::text = '1'
              AND ct.bank_response IS NOT NULL
              AND (ct.bank_response::jsonb #>> '{sessionData,tid}') IS NOT NULL
            ORDER BY ct.device_id, ct.id ASC
        )
        UPDATE devices d
        SET terminal_id = ft.tid
        FROM first_tid ft
        WHERE d.id = ft.device_id
    ");

    return response()->json([
        'success' => true,
        'message' => "Devices terminal_id updated successfully. Rows affected: {$affected}",
    ]);
});




Route::get('/admin/fix-devices-terminal-id-bank2', function () {
    $affected = DB::affectingStatement("
        WITH first_terminal AS (
            SELECT DISTINCT ON (ct.device_id)
                ct.device_id,
                (ct.bank_response::jsonb #>> '{receiptResponse,terminalId}') AS terminal_id
            FROM charity_transactions ct
            WHERE lower(ct.status) = 'success'
              AND ct.bank_transaction_id::text = '2'
              AND ct.bank_response IS NOT NULL
              AND (ct.bank_response::jsonb #>> '{receiptResponse,terminalId}') IS NOT NULL
            ORDER BY ct.device_id, ct.id ASC
        )
        UPDATE devices d
        SET terminal_id = ft.terminal_id
        FROM first_terminal ft
        WHERE d.id = ft.device_id
    ");

    return response()->json([
        'success' => true,
        'message' => "Devices terminal_id updated from bank_transaction_id=2 successfully. Rows affected: {$affected}",
    ]);
});



Route::get('/admin/fix-transactions-terminal-id', function () {
    $affected = DB::affectingStatement("
        UPDATE charity_transactions ct
        SET terminal_id = d.terminal_id
        FROM devices d
        WHERE d.id = ct.device_id
    ");

    return response()->json([
        'success' => true,
        'message' => "Charity transactions terminal_id updated successfully. Rows affected: {$affected}",
    ]);
});
 