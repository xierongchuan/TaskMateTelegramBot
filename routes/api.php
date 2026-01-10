<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ArchivedTaskController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DealershipController;
use App\Http\Controllers\Api\V1\ImportantLinkController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskGeneratorController;
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
    );

    // Закрытие сессии (логаут)
    Route::delete(
        '/session',
        [SessionController::class, 'destroy']
    )->middleware('auth:sanctum');

    // Получение текущего пользователя
    Route::get(
        '/session/current',
        [SessionController::class, 'current']
    )->middleware('auth:sanctum');

    // Проверка работоспособности API
    Route::get('/up', function () {
        return response()->json(['success' => true], 200);
    });

    Route::middleware('auth:sanctum')
        ->group(function () {
            // Users - READ операции
            Route::get('/users', [UserApiController::class, 'index']);
            Route::get('/users/{id}', [UserApiController::class, 'show']);
            Route::get('/users/{id}/status', [UserApiController::class, 'status']);

            // Users - WRITE операции (только managers и owners)
            Route::post('/users', [UserApiController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/users/{id}', [UserApiController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/users/{id}', [UserApiController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Dealerships - READ операции
            Route::get('/dealerships', [DealershipController::class, 'index']);
            Route::get('/dealerships/{id}', [DealershipController::class, 'show']);

            // Dealerships - WRITE операции (только owner)
            Route::post('/dealerships', [DealershipController::class, 'store'])
                ->middleware('role:owner');
            Route::put('/dealerships/{id}', [DealershipController::class, 'update'])
                ->middleware('role:owner');
            Route::delete('/dealerships/{id}', [DealershipController::class, 'destroy'])
                ->middleware('role:owner');

            // Shifts - READ операции
            Route::get('/shifts', [ShiftController::class, 'index']);
            Route::get('/shifts/current', [ShiftController::class, 'current']);
            Route::get('/shifts/statistics', [ShiftController::class, 'statistics']);
            Route::get('/shifts/my', [ShiftController::class, 'myShifts']);
            Route::get('/shifts/my/current', [ShiftController::class, 'myCurrentShift']);
            Route::get('/shifts/{id}', [ShiftController::class, 'show']);

            // Shifts - WRITE операции
            Route::post('/shifts', [ShiftController::class, 'store']);
            Route::put('/shifts/{id}', [ShiftController::class, 'update']);
            Route::delete('/shifts/{id}', [ShiftController::class, 'destroy']);

            // Tasks - READ операции
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/{id}', [TaskController::class, 'show']);

            // Tasks - WRITE операции (только managers и owners)
            Route::post('/tasks', [TaskController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/tasks/{id}', [TaskController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])
                ->middleware('role:manager,owner');
            Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus'])
                ->middleware('role:manager,owner');

            // Task Generators - READ операции
            Route::get('/task-generators', [TaskGeneratorController::class, 'index']);
            Route::get('/task-generators/{id}', [TaskGeneratorController::class, 'show']);
            Route::get('/task-generators/{id}/tasks', [TaskGeneratorController::class, 'generatedTasks']);
            Route::get('/task-generators/{id}/stats', [TaskGeneratorController::class, 'statistics']);

            // Task Generators - WRITE операции (только managers и owners)
            Route::post('/task-generators', [TaskGeneratorController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/task-generators/{id}', [TaskGeneratorController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/task-generators/{id}', [TaskGeneratorController::class, 'destroy'])
                ->middleware('role:manager,owner');
            Route::post('/task-generators/{id}/pause', [TaskGeneratorController::class, 'pause'])
                ->middleware('role:manager,owner');
            Route::post('/task-generators/{id}/resume', [TaskGeneratorController::class, 'resume'])
                ->middleware('role:manager,owner');
            Route::post('/task-generators/pause-all', [TaskGeneratorController::class, 'pauseAll'])
                ->middleware('role:owner');
            Route::post('/task-generators/resume-all', [TaskGeneratorController::class, 'resumeAll'])
                ->middleware('role:owner');

            // Archived Tasks
            Route::get('/archived-tasks', [ArchivedTaskController::class, 'index']);
            Route::get('/archived-tasks/export', [ArchivedTaskController::class, 'export']);
            Route::post('/archived-tasks/{id}/restore', [ArchivedTaskController::class, 'restore'])
                ->middleware('role:manager,owner');

            // Important Links - READ операции
            Route::get('/links', [ImportantLinkController::class, 'index']);
            Route::get('/links/{id}', [ImportantLinkController::class, 'show']);

            // Important Links - WRITE операции (только managers и owners)
            Route::post('/links', [ImportantLinkController::class, 'store'])
                ->middleware('role:manager,owner');
            Route::put('/links/{id}', [ImportantLinkController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::delete('/links/{id}', [ImportantLinkController::class, 'destroy'])
                ->middleware('role:manager,owner');

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);

            // Reports
            Route::get('/reports', [ReportController::class, 'index']);

            // Settings - READ операции
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::get('/settings/shift-config', [SettingsController::class, 'getShiftConfig']);
            Route::get('/settings/bot-config', [SettingsController::class, 'getBotConfig']);
            Route::get('/settings/{key}', [SettingsController::class, 'show']);
            Route::get('/bot/settings', [SettingsController::class, 'botSettings']);
            Route::get('/bot/settings/{key}', [SettingsController::class, 'botSetting']);

            // Settings - WRITE операции (только owner)
            Route::post('/settings/shift-config', [SettingsController::class, 'updateShiftConfig'])
                ->middleware('role:owner');
            Route::put('/settings/bot-config', [SettingsController::class, 'updateBotConfig'])
                ->middleware('role:manager,owner');
            Route::put('/settings/{key}', [SettingsController::class, 'update'])
                ->middleware('role:owner');

            // Dealership-specific settings
            Route::get('/settings/{dealership_id}', [SettingsController::class, 'showDealership']);
            Route::get('/settings/{dealership_id}/{key}', [SettingsController::class, 'showDealershipSetting']);
            Route::put('/settings/{dealership_id}/{key}', [SettingsController::class, 'updateDealershipSetting'])
                ->middleware('role:owner');

            // Notification Settings - managers and owners
            Route::get('/notification-settings', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'index'])
                ->middleware('role:manager,owner');
            Route::put('/notification-settings/{channelType}', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'update'])
                ->middleware('role:manager,owner');
            Route::post('/notification-settings/bulk', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'bulkUpdate'])
                ->middleware('role:manager,owner');
            Route::post('/notification-settings/reset', [\App\Http\Controllers\Api\V1\NotificationSettingController::class, 'resetToDefaults'])
                ->middleware('role:manager,owner');
        });
});

