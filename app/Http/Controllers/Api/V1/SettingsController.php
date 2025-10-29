<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * RESTful Settings management API
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Get all global settings
     *
     * GET /api/v1/settings
     */
    public function index(): JsonResponse
    {
        $settings = Setting::whereNull('dealership_id')->get();

        return response()->json([
            'success' => true,
            'data' => $settings->mapWithKeys(function ($setting) {
                return [$setting->key => $setting->getTypedValue()];
            }),
        ]);
    }

    /**
     * Get a specific global setting
     *
     * GET /api/v1/settings/{key}
     */
    public function show(string $key): JsonResponse
    {
        $value = $this->settingsService->get($key);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $value,
                'scope' => 'global'
            ],
        ]);
    }

    /**
     * Update a specific global setting
     *
     * PUT /api/v1/settings/{key}
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'nullable|in:string,integer,boolean,json,time',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();

            $setting = $this->settingsService->set(
                $key,
                $data['value'],
                null, // Global setting
                $data['type'] ?? 'string',
                $data['description'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully',
                'data' => [
                    'key' => $key,
                    'value' => $setting->getTypedValue(),
                    'scope' => 'global'
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get all settings for a specific dealership
     *
     * GET /api/v1/settings/{dealership_id}
     */
    public function showDealership(int $dealershipId): JsonResponse
    {
        $settings = Setting::where('dealership_id', $dealershipId)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'dealership_id' => $dealershipId,
                'settings' => $settings->mapWithKeys(function ($setting) {
                    return [$setting->key => $setting->getTypedValue()];
                }),
            ],
        ]);
    }

    /**
     * Get a specific dealership setting
     *
     * GET /api/v1/settings/{dealership_id}/{key}
     */
    public function showDealershipSetting(int $dealershipId, string $key): JsonResponse
    {
        $value = $this->settingsService->get($key, $dealershipId);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $value,
                'scope' => 'dealership',
                'dealership_id' => $dealershipId,
            ],
        ]);
    }

    /**
     * Update a specific dealership setting
     *
     * PUT /api/v1/settings/{dealership_id}/{key}
     */
    public function updateDealershipSetting(Request $request, int $dealershipId, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'nullable|in:string,integer,boolean,json,time',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();

            $setting = $this->settingsService->set(
                $key,
                $data['value'],
                $dealershipId,
                $data['type'] ?? 'string',
                $data['description'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully',
                'data' => [
                    'key' => $key,
                    'value' => $setting->getTypedValue(),
                    'scope' => 'dealership',
                    'dealership_id' => $dealershipId,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get all settings for the authenticated bot user
     *
     * GET /api/v1/bot/settings
     */
    public function botSettings(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->dealership_id) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or not associated with a dealership',
            ], 404);
        }

        $dealershipId = $user->dealership_id;

        // Get all settings with smart fallback (dealership -> global)
        $settings = $this->settingsService->getUserSettings($user);

        return response()->json([
            'success' => true,
            'data' => [
                'dealership_id' => $dealershipId,
                'user_id' => $user->id,
                'settings' => $settings,
            ],
        ]);
    }

    /**
     * Get a specific setting for the authenticated bot user
     *
     * GET /api/v1/bot/settings/{key}
     */
    public function botSetting(Request $request, string $key): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->dealership_id) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or not associated with a dealership',
            ], 404);
        }

        $dealershipId = $user->dealership_id;
        $value = $this->settingsService->getSettingWithFallback($key, $dealershipId);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $value,
                'dealership_id' => $dealershipId,
                'user_id' => $user->id,
            ],
        ]);
    }

    /**
     * Get shift configuration
     *
     * GET /api/v1/settings/shift-config
     */
    public function getShiftConfig(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') ? (int) $request->query('dealership_id') : null;

        $shiftConfig = [
            'shift_1_start_time' => $this->settingsService->getShiftStartTime($dealershipId, 1),
            'shift_1_end_time' => $this->settingsService->getShiftEndTime($dealershipId, 1),
            'shift_2_start_time' => $this->settingsService->getShiftStartTime($dealershipId, 2),
            'shift_2_end_time' => $this->settingsService->getShiftEndTime($dealershipId, 2),
            'late_tolerance_minutes' => $this->settingsService->getLateTolerance($dealershipId),
        ];

        return response()->json([
            'success' => true,
            'data' => $shiftConfig,
        ]);
    }

    /**
     * Update shift configuration
     *
     * POST /api/v1/settings/shift-config
     */
    public function updateShiftConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_1_start_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'shift_1_end_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'shift_2_start_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'shift_2_end_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'late_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'dealership_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $dealershipId = $data['dealership_id'] ?? null;
            unset($data['dealership_id']);

            $updatedSettings = [];
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    $type = $key === 'late_tolerance_minutes' ? 'integer' : 'time';
                    $this->settingsService->set($key, $value, $dealershipId, $type);
                    $updatedSettings[$key] = $value;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift configuration updated successfully',
                'data' => $updatedSettings,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}