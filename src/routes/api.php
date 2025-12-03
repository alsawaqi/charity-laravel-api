<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CharityStatsController;
use App\Http\Controllers\CharityDeviceStatusController;
use App\Http\Controllers\CharityTransactionsController;
use App\Http\Controllers\CharityLocationStatusController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me',    [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});


Route::prefix('stats/charity')->group(function () {
    Route::get('/devices/filters', [CharityDeviceStatusController::class, 'filters']);
    Route::get('/devices/status',  [CharityDeviceStatusController::class, 'index']); // from previous reply
});



Route::prefix('stats/charity')->group(function () {
    Route::get('/locations/filters', [CharityLocationStatusController::class, 'filters']);
    Route::get('/locations/status',  [CharityLocationStatusController::class, 'index']);
});

Route::get('/donations', [CharityTransactionsController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
Route::get('/stats/charity/transactions', [CharityTransactionsController::class, 'index_all']);

});
Route::post('/donations', [CharityTransactionsController::class, 'store']);



Route::get('/stats/charity/daily', [CharityStatsController::class, 'dailyTotals']);
Route::get('/stats/charity/totals', [CharityStatsController::class, 'totals']);
Route::get('/stats/charity/top-devices', [CharityStatsController::class, 'topDevices']);
Route::get('/stats/charity/top-location', [CharityStatsController::class, 'topLocation']);
Route::get('/stats/charity/top-banks', [CharityStatsController::class, 'topBanks']);
Route::get('/stats/charity/heatmap', [CharityStatsController::class, 'heatmap']); 
Route::get('/stats/charity/status', [CharityStatsController::class, 'index']);


Route::get('/ai-dashboard-search', [CharityStatsController::class, 'aiDashboardSearch']);


Route::get('/scalefusion/devices', function () {
    $token = '342792324df741dc836c12a7ea1adc99'; // store in config/services.php or .env

    $response = Http::withHeaders([
        'Accept'        => 'application/json',
        'Authorization' => 'Token ' . $token,
    ])->get('https://api.scalefusion.com/api/v3/devices.json');

    return $response->json();
});

Route::get('/scalefusion/device', function (Request $request) {


  $token = '342792324df741dc836c12a7ea1adc99'; // store in config/services.php or .env
     $deviceId = $request->device_id;
    $response = Http::withHeaders([
        'Accept'        => 'application/json',
        'Authorization' => 'Token ' . $token,
    ])->get('https://api.scalefusion.com/api/v3/devices/'.$deviceId.'.json');

    return $response->json();
});


 

Route::post('/scalefusion/device/reboot', function (Request $request) {
    // Validate input
    $data = $request->validate([
        'device_id' => 'required|integer',
    ]);

    $deviceId = $data['device_id'];

   
    $token = '342792324df741dc836c12a7ea1adc99';

    $response = Http::withHeaders([
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
        'Authorization' => 'Token ' . $token,
    ])->put("https://api.scalefusion.com/api/v1/devices/{$deviceId}/reboot.json", [
        // body can be empty for this endpoint
    ]);

    if (! $response->successful()) {
        return response()->json([
            'message' => 'Failed to reboot device',
            'status'  => $response->status(),
            'body'    => $response->json(),
        ], $response->status());
    }

    return response()->json($response->json(), 200);
});


 


Route::post('/scalefusion/device/alarm', function (Request $request) {
    // Validate input
    $data = $request->validate([
        'device_id' => 'required|integer',
    ]);

    $deviceId = $data['device_id'];

    // Move token to .env: SCALEFUSION_API_TOKEN=xxxx
   $token = '342792324df741dc836c12a7ea1adc99';

    $response = Http::withHeaders([
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
        'Authorization' => 'Token ' . $token,
    ])->post("https://api.scalefusion.com/api/v1/devices/{$deviceId}/send_alarm.json", [
        // Scalefusion doesn’t require a body for this endpoint, so this can be empty
    ]);

    if (! $response->successful()) {
        return response()->json([
            'message' => 'Failed to send alarm to device',
            'status'  => $response->status(),
            'body'    => $response->json(),
        ], $response->status());
    }

    return response()->json($response->json(), 200);
});