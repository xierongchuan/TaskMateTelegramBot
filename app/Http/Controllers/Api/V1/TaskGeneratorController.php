<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Enums\Role;

class TaskGeneratorController extends Controller
{
    /**
     * List all task generators with filtering.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = TaskGenerator::with(['creator', 'dealership', 'assignments.user']);

        // Filter by dealership
        if ($request->has('dealership_id')) {
            $query->where('dealership_id', $request->dealership_id);
        } elseif ($user->role !== Role::OWNER) {
            // Non-owner users see only their dealership's generators
            $query->where('dealership_id', $user->dealership_id);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by recurrence type
        if ($request->has('recurrence')) {
            $query->where('recurrence', $request->recurrence);
        }

        // Search by title
        if ($request->has('search')) {
            $query->where('title', 'ilike', '%' . $request->search . '%');
        }

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortField, $sortDir);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $generators = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $generators->map(fn($g) => $g->toApiArray()),
            'meta' => [
                'current_page' => $generators->currentPage(),
                'last_page' => $generators->lastPage(),
                'per_page' => $generators->perPage(),
                'total' => $generators->total(),
            ],
        ]);
    }

    /**
     * Show a single task generator.
     */
    public function show(Request $request, $id)
    {
        $generator = TaskGenerator::with(['creator', 'dealership', 'assignments.user'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
        ]);
    }

    /**
     * Create a new task generator.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'required|exists:auto_dealerships,id',
            'recurrence' => 'required|in:daily,weekly,monthly',
            'recurrence_time' => 'required|date_format:H:i',
            'deadline_time' => 'required|date_format:H:i',
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'task_type' => 'nullable|in:individual,group',
            'response_type' => 'nullable|in:acknowledge,complete',
            'priority' => 'nullable|in:low,medium,high',
            'tags' => 'nullable|array',
            'notification_settings' => 'nullable|array',
            'assignments' => 'required|array|min:1',
            'assignments.*' => 'exists:users,id',
        ]);

        // Validate recurrence requirements
        if ($validated['recurrence'] === 'weekly' && empty($validated['recurrence_day_of_week'])) {
            return response()->json([
                'success' => false,
                'message' => 'recurrence_day_of_week is required for weekly recurrence',
            ], 422);
        }

        if ($validated['recurrence'] === 'monthly' && empty($validated['recurrence_day_of_month'])) {
            return response()->json([
                'success' => false,
                'message' => 'recurrence_day_of_month is required for monthly recurrence',
            ], 422);
        }

        $user = $request->user();

        $generator = TaskGenerator::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'creator_id' => $user->id,
            'dealership_id' => $validated['dealership_id'],
            'recurrence' => $validated['recurrence'],
            'recurrence_time' => $validated['recurrence_time'] . ':00',
            'deadline_time' => $validated['deadline_time'] . ':00',
            'recurrence_day_of_week' => $validated['recurrence_day_of_week'] ?? null,
            'recurrence_day_of_month' => $validated['recurrence_day_of_month'] ?? null,
            'start_date' => Carbon::parse($validated['start_date'], 'Asia/Yekaterinburg')->setTimezone('UTC'),
            'end_date' => isset($validated['end_date'])
                ? Carbon::parse($validated['end_date'], 'Asia/Yekaterinburg')->setTimezone('UTC')
                : null,
            'task_type' => $validated['task_type'] ?? 'individual',
            'response_type' => $validated['response_type'] ?? 'acknowledge',
            'priority' => $validated['priority'] ?? 'medium',
            'tags' => $validated['tags'] ?? null,
            'notification_settings' => $validated['notification_settings'] ?? null,
            'is_active' => true,
        ]);

        // Create assignments
        foreach ($validated['assignments'] as $userId) {
            TaskGeneratorAssignment::create([
                'generator_id' => $generator->id,
                'user_id' => $userId,
            ]);
        }

        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator created successfully',
        ], 201);
    }

    /**
     * Update a task generator.
     */
    public function update(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'recurrence' => 'sometimes|in:daily,weekly,monthly',
            'recurrence_time' => 'sometimes|date_format:H:i',
            'deadline_time' => 'sometimes|date_format:H:i',
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'task_type' => 'nullable|in:individual,group',
            'response_type' => 'nullable|in:acknowledge,complete',
            'priority' => 'nullable|in:low,medium,high',
            'tags' => 'nullable|array',
            'notification_settings' => 'nullable|array',
            'assignments' => 'sometimes|array|min:1',
            'assignments.*' => 'exists:users,id',
        ]);

        $updateData = [];

        if (isset($validated['title'])) {
            $updateData['title'] = $validated['title'];
        }
        if (array_key_exists('description', $validated)) {
            $updateData['description'] = $validated['description'];
        }
        if (array_key_exists('comment', $validated)) {
            $updateData['comment'] = $validated['comment'];
        }
        if (isset($validated['recurrence'])) {
            $updateData['recurrence'] = $validated['recurrence'];
        }
        if (isset($validated['recurrence_time'])) {
            $updateData['recurrence_time'] = $validated['recurrence_time'] . ':00';
        }
        if (isset($validated['deadline_time'])) {
            $updateData['deadline_time'] = $validated['deadline_time'] . ':00';
        }
        if (array_key_exists('recurrence_day_of_week', $validated)) {
            $updateData['recurrence_day_of_week'] = $validated['recurrence_day_of_week'];
        }
        if (array_key_exists('recurrence_day_of_month', $validated)) {
            $updateData['recurrence_day_of_month'] = $validated['recurrence_day_of_month'];
        }
        if (isset($validated['start_date'])) {
            $updateData['start_date'] = Carbon::parse($validated['start_date'], 'Asia/Yekaterinburg')->setTimezone('UTC');
        }
        if (array_key_exists('end_date', $validated)) {
            $updateData['end_date'] = $validated['end_date']
                ? Carbon::parse($validated['end_date'], 'Asia/Yekaterinburg')->setTimezone('UTC')
                : null;
        }
        if (isset($validated['task_type'])) {
            $updateData['task_type'] = $validated['task_type'];
        }
        if (isset($validated['response_type'])) {
            $updateData['response_type'] = $validated['response_type'];
        }
        if (isset($validated['priority'])) {
            $updateData['priority'] = $validated['priority'];
        }
        if (array_key_exists('tags', $validated)) {
            $updateData['tags'] = $validated['tags'];
        }
        if (array_key_exists('notification_settings', $validated)) {
            $updateData['notification_settings'] = $validated['notification_settings'];
        }

        $generator->update($updateData);

        // Update assignments if provided
        if (isset($validated['assignments'])) {
            // Remove old assignments
            TaskGeneratorAssignment::where('generator_id', $generator->id)->delete();

            // Create new assignments
            foreach ($validated['assignments'] as $userId) {
                TaskGeneratorAssignment::create([
                    'generator_id' => $generator->id,
                    'user_id' => $userId,
                ]);
            }
        }

        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator updated successfully',
        ]);
    }

    /**
     * Delete a task generator.
     */
    public function destroy($id)
    {
        $generator = TaskGenerator::findOrFail($id);
        $generator->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task generator deleted successfully',
        ]);
    }

    /**
     * Pause a task generator.
     */
    public function pause($id)
    {
        $generator = TaskGenerator::findOrFail($id);
        $generator->update(['is_active' => false]);
        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator paused',
        ]);
    }

    /**
     * Resume a paused task generator.
     */
    public function resume($id)
    {
        $generator = TaskGenerator::findOrFail($id);
        $generator->update(['is_active' => true]);
        $generator->load(['creator', 'dealership', 'assignments.user']);

        return response()->json([
            'success' => true,
            'data' => $generator->toApiArray(),
            'message' => 'Task generator resumed',
        ]);
    }

    /**
     * Get tasks generated by this generator.
     */
    public function generatedTasks(Request $request, $id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $query = $generator->generatedTasks()
            ->with(['creator', 'dealership', 'assignments.user', 'responses']);

        // Filter by archived status
        if ($request->has('archived')) {
            $archived = filter_var($request->archived, FILTER_VALIDATE_BOOLEAN);
            if ($archived) {
                $query->whereNotNull('archived_at');
            } else {
                $query->whereNull('archived_at');
            }
        }

        $perPage = min($request->get('per_page', 15), 100);
        $tasks = $query->orderBy('scheduled_date', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $tasks->map(fn($t) => $t->toApiArray()),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * Get statistics for a task generator.
     *
     * Returns statistics for all time, week, month, and year periods.
     */
    public function statistics($id)
    {
        $generator = TaskGenerator::findOrFail($id);

        $allTime = $this->getStatsForPeriod($generator, null);
        $week = $this->getStatsForPeriod($generator, 7);
        $month = $this->getStatsForPeriod($generator, 30);
        $year = $this->getStatsForPeriod($generator, 365);

        // Calculate average completion time (in minutes)
        $avgCompletionTime = $this->calculateAverageCompletionTime($generator);

        return response()->json([
            'success' => true,
            'data' => [
                'generator_id' => $generator->id,
                'all_time' => $allTime,
                'week' => $week,
                'month' => $month,
                'year' => $year,
                'average_completion_time_minutes' => $avgCompletionTime,
            ],
        ]);
    }

    /**
     * Get statistics for a specific period.
     */
    private function getStatsForPeriod(TaskGenerator $generator, ?int $days): array
    {
        $query = $generator->generatedTasks();

        if ($days !== null) {
            $startDate = Carbon::now()->subDays($days)->startOfDay();
            $query = $query->where('scheduled_date', '>=', $startDate);
        }

        $tasksInPeriod = (clone $query)->get();

        $totalGenerated = $tasksInPeriod->count();

        $completedCount = $tasksInPeriod
            ->filter(fn($t) => $t->archived_at !== null && $t->archive_reason === 'completed')
            ->count();

        $expiredCount = $tasksInPeriod
            ->filter(fn($t) => $t->archived_at !== null && $t->archive_reason === 'expired')
            ->count();

        $pendingCount = $tasksInPeriod
            ->filter(fn($t) => $t->archived_at === null)
            ->count();

        // Calculate on-time completions (completed before deadline)
        $onTimeCount = 0;
        foreach ($tasksInPeriod as $task) {
            if ($task->archived_at !== null && $task->archive_reason === 'completed') {
                if ($task->deadline && Carbon::parse($task->archived_at)->lte(Carbon::parse($task->deadline))) {
                    $onTimeCount++;
                }
            }
        }

        $completionRate = $totalGenerated > 0
            ? round(($completedCount / $totalGenerated) * 100, 2)
            : 0;

        $onTimeRate = $completedCount > 0
            ? round(($onTimeCount / $completedCount) * 100, 2)
            : 0;

        return [
            'total_generated' => $totalGenerated,
            'completed_count' => $completedCount,
            'expired_count' => $expiredCount,
            'pending_count' => $pendingCount,
            'on_time_count' => $onTimeCount,
            'completion_rate' => $completionRate,
            'on_time_rate' => $onTimeRate,
        ];
    }

    /**
     * Calculate average completion time in minutes.
     */
    private function calculateAverageCompletionTime(TaskGenerator $generator): ?float
    {
        $completedTasks = $generator->generatedTasks()
            ->whereNotNull('archived_at')
            ->where('archive_reason', 'completed')
            ->whereNotNull('appear_date')
            ->get();

        if ($completedTasks->isEmpty()) {
            return null;
        }

        $totalMinutes = 0;
        $count = 0;

        foreach ($completedTasks as $task) {
            $appearDate = Carbon::parse($task->appear_date);
            $completedAt = Carbon::parse($task->archived_at);
            $minutes = $appearDate->diffInMinutes($completedAt);

            // Sanity check - if completion time is negative or extremely long, skip
            if ($minutes > 0 && $minutes < 60 * 24 * 7) { // Less than a week
                $totalMinutes += $minutes;
                $count++;
            }
        }

        return $count > 0 ? round($totalMinutes / $count, 2) : null;
    }
}

