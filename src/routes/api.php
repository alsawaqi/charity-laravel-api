<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\DeviceBrandController;
use App\Http\Controllers\DeviceModelController;
use App\Http\Controllers\CharityStatsController;
use App\Http\Controllers\MainLocationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\LocationLookupController;
 
use App\Http\Controllers\CharityLocationController;
use App\Http\Controllers\CommissionProfileController;
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



Route::get('/countries', [CountryController::class, 'index']);
Route::post('/countries', [CountryController::class, 'store']);
Route::get('/countries/{country}', [CountryController::class, 'show']);
Route::put('/countries/{country}', [CountryController::class, 'update']);
Route::delete('/countries/{country}', [CountryController::class, 'destroy']);



Route::get('/commission-profiles', [CommissionProfileController::class, 'index']);
Route::get('/commission-profiles/{commissionProfile}', [CommissionProfileController::class, 'show']);
Route::post('/commission-profiles', [CommissionProfileController::class, 'store']);
Route::put('/commission-profiles/{commissionProfile}', [CommissionProfileController::class, 'update']);
Route::delete('/commission-profiles/{commissionProfile}', [CommissionProfileController::class, 'destroy']);

Route::get('/regions', [RegionController::class, 'index']);
Route::post('/regions', [RegionController::class, 'store']);
Route::get('/regions/{region}', [RegionController::class, 'show']);
Route::put('/regions/{region}', [RegionController::class, 'update']);
Route::delete('/regions/{region}', [RegionController::class, 'destroy']);



Route::get('/cities', [CityController::class, 'index']);
Route::post('/cities', [CityController::class, 'store']);
Route::get('/cities/{city}', [CityController::class, 'show']);
Route::put('/cities/{city}', [CityController::class, 'update']);
Route::delete('/cities/{city}', [CityController::class, 'destroy']);


Route::get('/organizations', [OrganizationController::class, 'index']);
Route::get('/organizations/list', [OrganizationController::class, 'listAll']); // for parent dropdown

Route::post('/organizations', [OrganizationController::class, 'store']);
Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);
Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);


// Main locations CRUD
Route::get('/main-locations', [MainLocationController::class, 'index']);
Route::get('/main-locations/list', [MainLocationController::class, 'listAll']);
Route::post('/main-locations', [MainLocationController::class, 'store']);
Route::get('/main-locations/{mainLocation}', [MainLocationController::class, 'show']);
Route::put('/main-locations/{mainLocation}', [MainLocationController::class, 'update']);
Route::delete('/main-locations/{mainLocation}', [MainLocationController::class, 'destroy']);

Route::get('/charity-locations', [CharityLocationController::class, 'index']);
Route::post('/charity-locations', [CharityLocationController::class, 'store']);
Route::get('/charity-locations/{charityLocation}', [CharityLocationController::class, 'show']);
Route::put('/charity-locations/{charityLocation}', [CharityLocationController::class, 'update']);
Route::delete('/charity-locations/{charityLocation}', [CharityLocationController::class, 'destroy']);

Route::get('/device-brands', [DeviceBrandController::class, 'index']);
Route::post('/device-brands', [DeviceBrandController::class, 'store']);
Route::put('/device-brands/{deviceBrand}', [DeviceBrandController::class, 'update']);
Route::delete('/device-brands/{deviceBrand}', [DeviceBrandController::class, 'destroy']);

Route::get('/device-models', [DeviceModelController::class, 'index']);
Route::post('/device-models', [DeviceModelController::class, 'store']);
Route::put('/device-models/{deviceModel}', [DeviceModelController::class, 'update']);
Route::delete('/device-models/{deviceModel}', [DeviceModelController::class, 'destroy']);

Route::get('/locations/countries', [LocationLookupController::class, 'countries']);
Route::get('/locations/regions', [LocationLookupController::class, 'regions']);   // ?country_id=...
Route::get('/locations/cities', [LocationLookupController::class, 'cities']);   


 



Route::get('/banks', [BankController::class, 'index']);
Route::post('/banks', [BankController::class, 'store']);
Route::put('/banks/{bank}', [BankController::class, 'update']);
Route::delete('/banks/{bank}', [BankController::class, 'destroy']);


Route::prefix('stats/charity')->group(function () {
    Route::get('/locations/filters', [CharityLocationStatusController::class, 'filters']);
    Route::get('/locations/status',  [CharityLocationStatusController::class, 'index']);
});

Route::get('/donations', [CharityTransactionsController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
Route::get('/stats/charity/transactions', [CharityTransactionsController::class, 'index_all']);

});
Route::post('/donations', [CharityTransactionsController::class, 'store']);


Route::get   ('/devices',          [DeviceController::class, 'index']);
Route::get   ('/devices/{device}', [DeviceController::class, 'show']);
Route::post  ('/devices',          [DeviceController::class, 'store']);
Route::put   ('/devices/{device}', [DeviceController::class, 'update']);
Route::delete('/devices/{device}', [DeviceController::class, 'destroy']);


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