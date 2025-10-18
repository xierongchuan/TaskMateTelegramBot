<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Settings management API
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Get all settings for a dealership or global settings
     *
     * GET /api/v1/settings
     * Query params: dealership_id (optional)
     */
    public function index(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id');

        $settings = Setting::when($dealershipId, function ($query) use ($dealershipId) {
            return $query->where('dealership_id', $dealershipId);
        }, function ($query) {
            return $query->whereNull('dealership_id');
        })->get();

        return response()->json([
            'success' => true,
            'data' => $settings->map(function ($setting) {
                return [
                    'id' => $setting->id,
                    'key' => $setting->key,
                    'value' => $setting->getTypedValue(),
                    'type' => $setting->type,
                    'description' => $setting->description,
                    'dealership_id' => $setting->dealership_id,
                ];
            }),
        ]);
    }

    /**
     * Get a specific setting
     *
     * GET /api/v1/settings/{key}
     * Query params: dealership_id (optional)
     */
    public function show(Request $request, string $key): JsonResponse
    {
        $dealershipId = $request->query('dealership_id');
        $default = $request->query('default');

        $value = $this->settingsService->get($key, $dealershipId, $default);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $value,
            ],
        ]);
    }

    /**
     * Create or update a setting
     *
     * POST /api/v1/settings
     * Body: {key, value, type, dealership_id?, description?}
     */
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        if ($user->role !== \App\Enums\Role::OWNER->value && $user->role !== \App\Enums\Role::MANAGER->value) {
            return response()->json(['success' => false, 'message' => 'Доступ запрещен'], 403);
        }

        $validator = Validator::make($request->all(), [
            'key' => 'required|string|max:100',
            'value' => 'required',
            'type' => 'required|in:string,integer,boolean,json,time',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($user->role === \App\Enums\Role::MANAGER->value) {
            if (empty($data['dealership_id']) || $data['dealership_id'] !== $user->dealership_id) {
                return response()->json(['success' => false, 'message' => 'Вы не можете изменять эти настройки'], 403);
            }
        }

        $setting = $this->settingsService->set(
            $data['key'],
            $data['value'],
            $data['dealership_id'] ?? null,
            $data['type'],
            $data['description'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $setting->id,
                'key' => $setting->key,
                'value' => $setting->getTypedValue(),
                'type' => $setting->type,
                'description' => $setting->description,
                'dealership_id' => $setting->dealership_id,
            ],
        ], 201);
    }

    /**
     * Update a setting
     *
     * PUT /api/v1/settings/{id}
     * Body: {value, description?}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $setting = Setting::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        if ($user->role === \App\Enums\Role::MANAGER->value) {
            if ($setting->dealership_id !== $user->dealership_id) {
                return response()->json(['success' => false, 'message' => 'Доступ запрещен'], 403);
            }
        } elseif ($user->role !== \App\Enums\Role::OWNER->value) {
            return response()->json(['success' => false, 'message' => 'Доступ запрещен'], 403);
        }

        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $setting->setTypedValue($data['value']);
        if (isset($data['description'])) {
            $setting->description = $data['description'];
        }
        $setting->save();

        // Clear cache
        $this->settingsService->clearCache();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $setting->id,
                'key' => $setting->key,
                'value' => $setting->getTypedValue(),
                'type' => $setting->type,
                'description' => $setting->description,
                'dealership_id' => $setting->dealership_id,
            ],
        ]);
    }

    /**
     * Delete a setting
     *
     * DELETE /api/v1/settings/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $setting = Setting::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        if ($user->role === \App\Enums\Role::MANAGER->value) {
            if ($setting->dealership_id !== $user->dealership_id) {
                return response()->json(['success' => false, 'message' => 'Доступ запрещен'], 403);
            }
        } elseif ($user->role !== \App\Enums\Role::OWNER->value) {
            return response()->json(['success' => false, 'message' => 'Доступ запрещен'], 403);
        }

        $setting->delete();

        // Clear cache
        $this->settingsService->clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Setting deleted successfully',
        ]);
    }

    /**
     * Get shift configuration for a dealership
     *
     * GET /api/v1/settings/shift-config
     * Query params: dealership_id (optional)
     */
    public function getShiftConfig(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id');

        return response()->json([
            'success' => true,
            'data' => [
                'shift_1_start_time' => $this->settingsService->getShiftStartTime($dealershipId, 1),
                'shift_1_end_time' => $this->settingsService->getShiftEndTime($dealershipId, 1),
                'shift_2_start_time' => $this->settingsService->getShiftStartTime($dealershipId, 2),
                'shift_2_end_time' => $this->settingsService->getShiftEndTime($dealershipId, 2),
                'late_tolerance_minutes' => $this->settingsService->getLateTolerance($dealershipId),
            ],
        ]);
    }

    /**
     * Update shift configuration for a dealership
     *
     * POST /api/v1/settings/shift-config
     * Body: {shift_1_start_time?, shift_1_end_time?, shift_2_start_time?, shift_2_end_time?, late_tolerance_minutes?, dealership_id?}
     */
    public function updateShiftConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_1_start_time' => 'nullable|date_format:H:i',
            'shift_1_end_time' => 'nullable|date_format:H:i',
            'shift_2_start_time' => 'nullable|date_format:H:i',
            'shift_2_end_time' => 'nullable|date_format:H:i',
            'late_tolerance_minutes' => 'nullable|integer|min:0|max:120',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $dealershipId = $data['dealership_id'] ?? null;

        $updated = [];

        if (isset($data['shift_1_start_time'])) {
            $this->settingsService->set('shift_1_start_time', $data['shift_1_start_time'], $dealershipId, 'time');
            $updated['shift_1_start_time'] = $data['shift_1_start_time'];
        }

        if (isset($data['shift_1_end_time'])) {
            $this->settingsService->set('shift_1_end_time', $data['shift_1_end_time'], $dealershipId, 'time');
            $updated['shift_1_end_time'] = $data['shift_1_end_time'];
        }

        if (isset($data['shift_2_start_time'])) {
            $this->settingsService->set('shift_2_start_time', $data['shift_2_start_time'], $dealershipId, 'time');
            $updated['shift_2_start_time'] = $data['shift_2_start_time'];
        }

        if (isset($data['shift_2_end_time'])) {
            $this->settingsService->set('shift_2_end_time', $data['shift_2_end_time'], $dealershipId, 'time');
            $updated['shift_2_end_time'] = $data['shift_2_end_time'];
        }

        if (isset($data['late_tolerance_minutes'])) {
            $this->settingsService->set('late_tolerance_minutes', $data['late_tolerance_minutes'], $dealershipId, 'integer');
            $updated['late_tolerance_minutes'] = $data['late_tolerance_minutes'];
        }

        return response()->json([
            'success' => true,
            'message' => 'Shift configuration updated successfully',
            'data' => $updated,
        ]);
    }

    /**
     * Manually trigger task archiving
     *
     * POST /api/v1/settings/archive-tasks
     */
    public function archiveTasks(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        if ($user->role !== \App\Enums\Role::OWNER->value) {
            return response()->json(['success' => false, 'message' => 'Доступ запрещен'], 403);
        }

        $days = (int) $request->input('days');
        \App\Jobs\ArchiveOldTasksJob::dispatch($days > 0 ? $days : null);

        return response()->json([
            'success' => true,
            'message' => 'Архивация старых задач запущена в фоновом режиме.',
        ]);
    }
}
