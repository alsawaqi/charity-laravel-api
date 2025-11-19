<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

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


Route::get('/scalefusion/devices', function () {
    $token = '342792324df741dc836c12a7ea1adc99'; // store in config/services.php or .env

    $response = Http::withHeaders([
        'Accept'        => 'application/json',
        'Authorization' => 'Token ' . $token,
    ])->get('https://api.scalefusion.com/api/v3/devices.json');

    return $response->json();
});