<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Owners can filter by dealership, managers can only see their own
        if ($user->role === \App\Enums\Role::OWNER->value) {
            $dealershipId = $request->query('dealership_id');
            $query = $dealershipId ? Task::where('dealership_id', $dealershipId) : Task::query();
        } else {
            $query = Task::where('dealership_id', $user->dealership_id);
        }

        $query->with(['creator', 'dealership', 'assignments.user', 'responses']);

        $perPage = (int) $request->query('per_page', '15');
        $taskType = $request->query('task_type');
        $isActive = $request->query('is_active');
        $tags = $request->query('tags');
        $status = $request->query('status'); // completed, postponed, overdue
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $assignedUserId = $request->query('user_id');

        if ($taskType) {
            $query->where('task_type', $taskType);
        }

        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        if ($tags) {
            $tagsArray = is_array($tags) ? $tags : explode(',', $tags);
            $query->where(function ($q) use ($tagsArray) {
                foreach ($tagsArray as $tag) {
                    $q->orWhereJsonContains('tags', trim($tag));
                }
            });
        }

        if ($assignedUserId) {
            $query->whereHas('assignments', function ($q) use ($assignedUserId) {
                $q->where('user_id', $assignedUserId);
            });
        }

        if ($status) {
            switch ($status) {
                case 'completed':
                    $query->whereHas('responses', fn($q) => $q->where('status', 'completed'));
                    break;
                case 'postponed':
                    $query->where('postpone_count', '>', 0);
                    break;
                case 'overdue':
                    $query->where('deadline', '<', Carbon::now())
                          ->whereDoesntHave('responses', fn($q) => $q->where('status', 'completed'));
                    break;
            }
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', Carbon::parse($dateFrom));
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', Carbon::parse($dateTo));
        }

        $tasks = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($tasks);
    }

    public function show($id)
    {
        $task = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses.user'
        ])->find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        return response()->json($task);
    }

    public function store(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->role !== \App\Enums\Role::OWNER->value && $user->role !== \App\Enums\Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'required|exists:auto_dealerships,id',
            'appear_date' => 'nullable|date',
            'deadline' => 'nullable|date',
            'recurrence' => 'nullable|string|in:daily,weekly,monthly',
            'task_type' => 'required|string|in:individual,group',
            'response_type' => 'required|string|in:notification,execution',
            'tags' => 'nullable|array',
            'assigned_users' => 'required|array',
            'assigned_users.*' => 'exists:users,id',
        ]);

        // Manager can only create tasks in their own dealership
        if ($user->role === \App\Enums\Role::MANAGER->value && $validated['dealership_id'] !== $user->dealership_id) {
            return response()->json(['message' => 'Вы не можете создавать задачи в этом салоне'], 403);
        }

        $task = Task::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'creator_id' => $user->id,
            'dealership_id' => $validated['dealership_id'],
            'appear_date' => $validated['appear_date'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'recurrence' => $validated['recurrence'] ?? null,
            'task_type' => $validated['task_type'],
            'response_type' => $validated['response_type'],
            'tags' => $validated['tags'] ?? null,
        ]);

        // Assign users
        if (!empty($validated['assigned_users'])) {
            foreach ($validated['assigned_users'] as $userId) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $userId,
                ]);
            }
        }

        return response()->json($task->load(['assignments.user']), 201);
    }

    public function update(Request $request, Task $task)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->role !== \App\Enums\Role::OWNER->value && $user->role !== \App\Enums\Role::MANAGER->value) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        // Manager can only update tasks in their own dealership
        if ($user->role === \App\Enums\Role::MANAGER->value && $task->dealership_id !== $user->dealership_id) {
            return response()->json(['message' => 'Вы не можете редактировать эту задачу'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'nullable|date',
            'deadline' => 'nullable|date',
            'recurrence' => 'nullable|string|in:daily,weekly,monthly',
            'task_type' => 'sometimes|required|string|in:individual,group',
            'response_type' => 'sometimes|required|string|in:notification,execution',
            'tags' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'user_status_update' => 'nullable|array',
            'user_status_update.user_id' => 'required_with:user_status_update|exists:users,id',
            'user_status_update.status' => 'required_with:user_status_update|in:completed,postponed,acknowledged',
        ]);

        // Handle manual status update
        if (isset($validated['user_status_update'])) {
            $updateData = $validated['user_status_update'];
            \App\Models\TaskResponse::updateOrCreate(
                [
                    'task_id' => $task->id,
                    'user_id' => $updateData['user_id'],
                ],
                [
                    'status' => $updateData['status'],
                    'responded_at' => Carbon::now(),
                    'comment' => 'Статус изменен вручную администратором',
                ]
            );
            unset($validated['user_status_update']);
        }

        $task->update($validated);

        return response()->json($task->load(['assignments.user', 'responses.user']));
    }

    public function postponed(Request $request)
    {
        $dealershipId = $request->query('dealership_id');

        $query = Task::with(['creator', 'dealership', 'responses'])
            ->where('postpone_count', '>', 0)
            ->where('is_active', true);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        $postponedTasks = $query->orderByDesc('postpone_count')->get();

        return response()->json($postponedTasks);
    }
}
