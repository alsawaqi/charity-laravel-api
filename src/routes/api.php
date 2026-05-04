<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankDeviceController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\CashCollectionController;
use App\Http\Controllers\CharityDeviceStatusController;
use App\Http\Controllers\CharityLocationController;
use App\Http\Controllers\CharityLocationStatusController;
use App\Http\Controllers\CharityStatsController;
use App\Http\Controllers\CharityTransactionsController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CommissionProfileController;
use App\Http\Controllers\CommissionShareReportController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DashboardAccessController;
use App\Http\Controllers\DeviceBrandController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceLocationController;
use App\Http\Controllers\DeviceModelController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Controllers\MainLocationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\ScalefusionController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

Route::post('/donations', [CharityTransactionsController::class, 'store']);
Route::post('/donations-dhofar', [CharityTransactionsController::class, 'store_dhofar']);
Route::post('/donations-bank-nizwa', [CharityTransactionsController::class, 'store_bank_nizwa']);
Route::post('/device-banks/resolve', [BankDeviceController::class, 'resolve']);
Route::post('/device-banks/password', [BankDeviceController::class, 'updatePassword']);

Route::middleware(['auth:sanctum', 'dashboard.access'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::prefix('dashboard-access')
        ->middleware('dashboard.permission:dashboard.access.manage')
        ->group(function () {
            Route::get('permissions', [DashboardAccessController::class, 'permissions']);
            Route::get('roles', [DashboardAccessController::class, 'roles']);
            Route::post('roles', [DashboardAccessController::class, 'storeRole']);
            Route::put('roles/{dashboardRole}', [DashboardAccessController::class, 'updateRole']);
            Route::delete('roles/{dashboardRole}', [DashboardAccessController::class, 'destroyRole']);

            Route::get('users', [DashboardAccessController::class, 'users']);
            Route::post('users', [DashboardAccessController::class, 'storeUser']);
            Route::put('users/{user}', [DashboardAccessController::class, 'updateUser']);
            Route::delete('users/{user}', [DashboardAccessController::class, 'destroyUser']);
            Route::get('organizations', [DashboardAccessController::class, 'organizations']);
        });

    /*
     * Shared reference data for approved dashboard users.
     * Mutations are protected separately below.
     */
    Route::get('/countries', [CountryController::class, 'index']);
    Route::get('/countries/{country}', [CountryController::class, 'show']);
    Route::get('/regions', [RegionController::class, 'index']);
    Route::get('/regions/{region}', [RegionController::class, 'show']);
    Route::get('/cities', [CityController::class, 'index']);
    Route::get('/cities/{city}', [CityController::class, 'show']);
    Route::get('/districts', [DistrictController::class, 'index']);
    Route::get('/districts/{district}', [DistrictController::class, 'show']);

    Route::get('/activities', [ActivityController::class, 'index']);
    Route::get('/activities/{activity}', [ActivityController::class, 'show']);
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/list', [CompanyController::class, 'listAll']);
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->whereNumber('company');

    Route::get('/banks', [BankController::class, 'index']);
    Route::get('/commission-profiles', [CommissionProfileController::class, 'index']);
    Route::get('/commission-profiles/{commissionProfile}', [CommissionProfileController::class, 'show']);
    Route::get('/device-brands', [DeviceBrandController::class, 'index']);
    Route::get('/device-models', [DeviceModelController::class, 'index']);

    Route::get('/main-locations', [MainLocationController::class, 'index']);
    Route::get('/main-locations/list', [MainLocationController::class, 'listAll']);
    Route::get('/main-locations/{mainLocation}', [MainLocationController::class, 'show']);

    Route::get('/charity-locations', [CharityLocationController::class, 'index']);
    Route::get('/charity-locations/list', [CharityLocationController::class, 'listAll']);
    Route::get('/charity-locations/{charityLocation}', [CharityLocationController::class, 'show']);

    Route::get('/locations/districts', [LocationLookupController::class, 'districts']);
    Route::get('/locations/countries', [LocationLookupController::class, 'countries']);
    Route::get('/locations/regions', [LocationLookupController::class, 'regions']);
    Route::get('/locations/cities', [LocationLookupController::class, 'cities']);

    Route::get('/organizations/list', [OrganizationController::class, 'listAll']);

    Route::get('/organizations', [OrganizationController::class, 'index'])
        ->middleware('dashboard.permission:dashboard.organizations.manage');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
        ->middleware('dashboard.permission:dashboard.organizations.manage');

    Route::get('/devices', [DeviceController::class, 'index'])
        ->middleware('dashboard.permission:dashboard.devices.live.view,dashboard.devices.manage');
    Route::get('/devices/{device}', [DeviceController::class, 'editorShow'])
        ->middleware('dashboard.permission:dashboard.devices.live.view,dashboard.devices.manage');
    Route::get('/devices/by-kiosk/{kiosk_id}', [DeviceController::class, 'showByKiosk'])
        ->middleware('dashboard.permission:dashboard.device-geo-location.view,dashboard.devices.live.view');
    Route::get('/devices/export', [DeviceController::class, 'export'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
    Route::get('/device-banks', [BankDeviceController::class, 'index'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
    Route::get('/device-banks/{device}', [BankDeviceController::class, 'show'])
        ->middleware('dashboard.permission:dashboard.devices.manage');

    Route::get('/device-locations/filters', [DeviceLocationController::class, 'filters'])
        ->middleware('dashboard.permission:dashboard.device-locations.view');
    Route::get('/device-locations/devices', [DeviceLocationController::class, 'devices'])
        ->middleware('dashboard.permission:dashboard.device-locations.view');

    Route::get('/donations', [CharityTransactionsController::class, 'index'])
        ->middleware('dashboard.permission:dashboard.overview.view');
    Route::get('/charity-transactions', [CharityTransactionsController::class, 'filter'])
        ->middleware('dashboard.permission:dashboard.transactions.view');
    Route::get('/stats/charity/transactions', [CharityTransactionsController::class, 'index_all'])
        ->middleware('dashboard.permission:dashboard.charity.view');

    Route::prefix('stats/charity')->group(function () {
        Route::get('/devices/filters', [CharityDeviceStatusController::class, 'filters'])
            ->middleware('dashboard.permission:dashboard.status.devices.view');
        Route::get('/devices/status', [CharityDeviceStatusController::class, 'index'])
            ->middleware('dashboard.permission:dashboard.status.devices.view');

        Route::get('/locations/filters', [CharityLocationStatusController::class, 'filters'])
            ->middleware('dashboard.permission:dashboard.status.locations.view');
        Route::get('/locations/status', [CharityLocationStatusController::class, 'index'])
            ->middleware('dashboard.permission:dashboard.status.locations.view');

        Route::get('/daily', [CharityStatsController::class, 'dailyTotals'])
            ->middleware('dashboard.permission:dashboard.overview.view');
        Route::get('/totals', [CharityStatsController::class, 'totals'])
            ->middleware('dashboard.permission:dashboard.overview.view');
        Route::get('/top-devices', [CharityStatsController::class, 'topDevices'])
            ->middleware('dashboard.permission:dashboard.overview.view');
        Route::get('/top-location', [CharityStatsController::class, 'topLocation'])
            ->middleware('dashboard.permission:dashboard.overview.view');
        Route::get('/top-banks', [CharityStatsController::class, 'topBanks'])
            ->middleware('dashboard.permission:dashboard.overview.view');
        Route::get('/heatmap', [CharityStatsController::class, 'heatmap'])
            ->middleware('dashboard.permission:dashboard.overview.view');
        Route::get('/status', [CharityStatsController::class, 'index'])
            ->middleware('dashboard.permission:dashboard.search.view');
    });

    Route::get('/commission-share-report', [CommissionShareReportController::class, 'index'])
        ->middleware('dashboard.permission:dashboard.commission-share-report.view');

    Route::post('/bank-reconciliation/preview', [BankReconciliationController::class, 'preview'])
        ->middleware('dashboard.permission:dashboard.bank-reconciliation.manage');
    Route::post('/bank-reconciliation/commit', [BankReconciliationController::class, 'commit'])
        ->middleware('dashboard.permission:dashboard.bank-reconciliation.manage');

    Route::get('/cash-collections/filters', [CashCollectionController::class, 'filters'])
        ->middleware('dashboard.permission:dashboard.cash-collections.manage');
    Route::get('/cash-collections', [CashCollectionController::class, 'index'])
        ->middleware('dashboard.permission:dashboard.cash-collections.manage');
    Route::get('/cash-collections/export', [CashCollectionController::class, 'export'])
        ->middleware('dashboard.permission:dashboard.cash-collections.manage');
    Route::get('/cash-collections/{cashCollection}', [CashCollectionController::class, 'show'])
        ->middleware('dashboard.permission:dashboard.cash-collections.manage');
    Route::post('/cash-collections', [CashCollectionController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.cash-collections.manage');

    Route::get('/ai-dashboard-search', [CharityStatsController::class, 'aiDashboardSearch'])
        ->middleware('dashboard.permission:dashboard.ai.view');

    Route::get('/scalefusion/device-availability/filters', [ScalefusionController::class, 'deviceAvailabilityFilters'])
        ->middleware('dashboard.permission:dashboard.device-availability.view');
    Route::get('/scalefusion/device-availabilities', [ScalefusionController::class, 'deviceAvailabilities'])
        ->middleware('dashboard.permission:dashboard.device-availability.view');

    Route::prefix('scalefusion')->middleware(
        'dashboard.permission:dashboard.devices.live.view,dashboard.device-geo-location.view'
    )->group(function () {
        Route::get('/devices', [ScalefusionController::class, 'devices']);
        Route::get('/device', [ScalefusionController::class, 'device']);
        Route::get('/device/locations', [ScalefusionController::class, 'deviceLocations']);
        Route::post('/device/reboot', [ScalefusionController::class, 'reboot']);
        Route::post('/device/alarm', [ScalefusionController::class, 'alarm']);
        Route::post('/device/broadcast-message', [ScalefusionController::class, 'broadcastMessage']);
        Route::post('/device/action', [ScalefusionController::class, 'action']);
        Route::post('/device/clear-app-data', [ScalefusionController::class, 'clearAppData']);
        Route::post('/devices/lock', [ScalefusionController::class, 'lock']);
        Route::post('/devices/unlock', [ScalefusionController::class, 'unlock']);
        Route::get('/location-geofence', [ScalefusionController::class, 'locationGeofence']);
    });

    Route::post('/countries', [CountryController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.countries.manage');
    Route::put('/countries/{country}', [CountryController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.countries.manage');
    Route::delete('/countries/{country}', [CountryController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.countries.manage');

    Route::post('/regions', [RegionController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.regions.manage');
    Route::put('/regions/{region}', [RegionController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.regions.manage');
    Route::delete('/regions/{region}', [RegionController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.regions.manage');

    Route::post('/cities', [CityController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.cities.manage');
    Route::put('/cities/{city}', [CityController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.cities.manage');
    Route::delete('/cities/{city}', [CityController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.cities.manage');

    Route::post('/districts', [DistrictController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.districts.manage');
    Route::put('/districts/{district}', [DistrictController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.districts.manage');
    Route::delete('/districts/{district}', [DistrictController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.districts.manage');

    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.organizations.manage');
    Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.organizations.manage');
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.organizations.manage');

    Route::post('/main-locations', [MainLocationController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.main-locations.manage');
    Route::put('/main-locations/{mainLocation}', [MainLocationController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.main-locations.manage');
    Route::delete('/main-locations/{mainLocation}', [MainLocationController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.main-locations.manage');

    Route::post('/charity-locations/bulk', [CharityLocationController::class, 'storeBulk'])
        ->middleware('dashboard.permission:dashboard.charity-locations.manage');
    Route::post('/charity-locations', [CharityLocationController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.charity-locations.manage');
    Route::put('/charity-locations/{charityLocation}', [CharityLocationController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.charity-locations.manage');
    Route::delete('/charity-locations/{charityLocation}', [CharityLocationController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.charity-locations.manage');

    Route::post('/device-brands', [DeviceBrandController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.device-brands.manage');
    Route::put('/device-brands/{deviceBrand}', [DeviceBrandController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.device-brands.manage');
    Route::delete('/device-brands/{deviceBrand}', [DeviceBrandController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.device-brands.manage');

    Route::post('/device-models', [DeviceModelController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.device-models.manage');
    Route::put('/device-models/{deviceModel}', [DeviceModelController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.device-models.manage');
    Route::delete('/device-models/{deviceModel}', [DeviceModelController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.device-models.manage');

    Route::post('/banks', [BankController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.banks.manage');
    Route::put('/banks/{bank}', [BankController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.banks.manage');
    Route::delete('/banks/{bank}', [BankController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.banks.manage');

    Route::post('/commission-profiles', [CommissionProfileController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.commission-profiles.manage');
    Route::put('/commission-profiles/{commissionProfile}', [CommissionProfileController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.commission-profiles.manage');
    Route::delete('/commission-profiles/{commissionProfile}', [CommissionProfileController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.commission-profiles.manage');

    Route::post('/companies', [CompanyController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.companies.manage');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.companies.manage');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.companies.manage');

    Route::post('/activities', [ActivityController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.activities.manage');
    Route::put('/activities/{activity}', [ActivityController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.activities.manage');
    Route::delete('/activities/{activity}', [ActivityController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.activities.manage');

    Route::post('/devices', [DeviceController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
    Route::put('/devices/{device}', [DeviceController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
    Route::post('/device-banks', [BankDeviceController::class, 'store'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
    Route::put('/device-banks/{device}', [BankDeviceController::class, 'update'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
    Route::delete('/device-banks/{device}', [BankDeviceController::class, 'destroy'])
        ->middleware('dashboard.permission:dashboard.devices.manage');
});
