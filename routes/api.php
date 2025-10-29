<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DealershipController;
use App\Http\Controllers\Api\V1\ImportantLinkController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserApiController;
use App\Http\Controllers\FrontController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Webhook для Telegram
Route::post('/webhook', [FrontController::class, 'webhook']);

Route::prefix('v1')->group(function () {
    // Открытие сессии (логин)
    Route::post(
        '/session',
        [SessionController::class, 'store']
    )->middleware('throttle:100,1440');

    // Закрытие сессии (логаут)
    Route::delete(
        '/session',
        [SessionController::class, 'destroy']
    )->middleware(['auth:sanctum', 'throttle:5,1440']);

    // Получение текущего пользователя
    Route::get(
        '/session/current',
        [SessionController::class, 'current']
    )->middleware(['auth:sanctum', 'throttle:100,1440']);

    // Проверка работоспособности API
    Route::get('/up', function () {
        return response()->json(['success' => true], 200);
    })->middleware('throttle:100,1');

    Route::middleware([
            'auth:sanctum',
            'throttle:220,1'
        ])
        ->group(function () {
            // Users
            Route::get('/users', [UserApiController::class, 'index']);
            Route::get('/users/{id}', [UserApiController::class, 'show']);
            Route::get('/users/{id}/status', [UserApiController::class, 'status']);

            // Only managers and owners can create, update, and delete users
            Route::post('/users', [UserApiController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/users/{id}', [UserApiController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/users/{id}', [UserApiController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Dealerships
            Route::get('/dealerships', [DealershipController::class, 'index']);
            Route::get('/dealerships/{id}', [DealershipController::class, 'show']);

            // Only managers and owners can manage dealerships
            Route::post('/dealerships', [DealershipController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/dealerships/{id}', [DealershipController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/dealerships/{id}', [DealershipController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Shifts
            Route::get('/shifts', [ShiftController::class, 'index']);
            Route::post('/shifts', [ShiftController::class, 'store']);
            Route::get('/shifts/current', [ShiftController::class, 'current']);
            Route::get('/shifts/statistics', [ShiftController::class, 'statistics']);
            Route::get('/shifts/my', [ShiftController::class, 'myShifts']);
            Route::get('/shifts/my/current', [ShiftController::class, 'myCurrentShift']);
            Route::get('/shifts/{id}', [ShiftController::class, 'show']);
            Route::put('/shifts/{id}', [ShiftController::class, 'update']);
            Route::delete('/shifts/{id}', [ShiftController::class, 'destroy']);

            // Tasks
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/postponed', [TaskController::class, 'postponed']);
            Route::get('/tasks/{id}', [TaskController::class, 'show']);

            // Only managers and owners can manage tasks
            Route::post('/tasks', [TaskController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/tasks/{id}', [TaskController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Important Links
            Route::get('/links', [ImportantLinkController::class, 'index']);
            Route::get('/links/{id}', [ImportantLinkController::class, 'show']);

            // Only managers and owners can manage links
            Route::post('/links', [ImportantLinkController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/links/{id}', [ImportantLinkController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/links/{id}', [ImportantLinkController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);

            // Settings
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::get('/settings/shift-config', [SettingsController::class, 'getShiftConfig']);
            Route::post('/settings/shift-config', [SettingsController::class, 'updateShiftConfig'])
                ->middleware('role:manager,owner');
            Route::get('/settings/{key}', [SettingsController::class, 'show']);
            Route::put('/settings/{key}', [SettingsController::class, 'update'])
                ->middleware('role:manager,owner');

            // Dealership-specific settings
            Route::get('/settings/{dealership_id}', [SettingsController::class, 'showDealership']);
            Route::get('/settings/{dealership_id}/{key}', [SettingsController::class, 'showDealershipSetting']);
            Route::put('/settings/{dealership_id}/{key}', [SettingsController::class, 'updateDealershipSetting'])
                ->middleware('role:manager,owner');

            // Bot user settings (automatic dealership detection)
            Route::get('/bot/settings', [SettingsController::class, 'botSettings']);
            Route::get('/bot/settings/{key}', [SettingsController::class, 'botSetting']);
        });
});
