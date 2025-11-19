<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::get('/scalefusion/devices', function () {
    $token = '342792324df741dc836c12a7ea1adc99'; // store in config/services.php or .env

    $response = Http::withHeaders([
        'Accept'        => 'application/json',
        'Authorization' => 'Token ' . $token,
    ])->get('https://api.scalefusion.com/api/v3/devices.json');

    return $response->json();
});