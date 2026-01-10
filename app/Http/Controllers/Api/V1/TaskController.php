<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Enums\Role;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');

        // Получаем все параметры фильтрации
        $dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== '' ? (int) $request->query('dealership_id') : null;
        $taskType = $request->query('task_type');
        $isActive = $request->query('is_active');
        $tags = $request->query('tags');
        $creatorId = $request->query('creator_id') !== null && $request->query('creator_id') !== '' ? (int) $request->query('creator_id') : null;
        $responseType = $request->query('response_type');
        $deadlineFrom = $request->query('deadline_from');
        $deadlineTo = $request->query('deadline_to');
        $hasDeadline = $request->query('has_deadline');
        $search = $request->query('search');
        $status = $request->query('status');
        $priority = $request->query('priority');
        $dateRange = $request->query('date_range');
        $generatorId = $request->query('generator_id') !== null && $request->query('generator_id') !== '' ? (int) $request->query('generator_id') : null;
        $fromGenerator = $request->query('from_generator'); // 'yes', 'no', or null for all

        $query = Task::with(['creator', 'dealership', 'assignments.user', 'responses']);

        // Фильтрация по диапазону дат
        if ($dateRange && $dateRange !== 'all') {
            $dateStart = null;
            $dateEnd = null;

            switch ($dateRange) {
                case 'today':
                    $dateStart = Carbon::today();
                    $dateEnd = Carbon::today()->endOfDay();
                    break;
                case 'week':
                    $dateStart = Carbon::now()->startOfWeek();
                    $dateEnd = Carbon::now()->endOfWeek();
                    break;
                case 'month':
                    $dateStart = Carbon::now()->startOfMonth();
                    $dateEnd = Carbon::now()->endOfMonth();
                    break;
            }

            if ($dateStart && $dateEnd) {
                if ($status === 'completed') {
                    $query->whereHas('responses', function ($q) use ($dateStart, $dateEnd) {
                        $q->where('status', 'completed')
                          ->whereBetween('responded_at', [$dateStart, $dateEnd]);
                    });
                } else {
                    $query->whereBetween('deadline', [$dateStart, $dateEnd]);
                }
            }
        }

        // Фильтрация по автосалону
        /** @var User $currentUser */
        $currentUser = $request->user();

        // Scope tasks for non-owners: restricted to accessible dealerships
        if ($currentUser->role !== Role::OWNER) {
            $accessibleIds = $currentUser->getAccessibleDealershipIds();

            // If explicit filter is provided, validate it's accessible
            if ($dealershipId) {
                if (!in_array($dealershipId, $accessibleIds)) {
                    // If filtering by inaccessible dealership, return empty
                    $query->where('dealership_id', -1);
                } else {
                    $query->where('dealership_id', $dealershipId);
                }
            } else {
                // Otherwise, show tasks from all accessible dealerships or tasks assigned to user
                // Policy: Manager can see tasks in their dealerships OR tasks assigned to them even if dealership is different (unlikely but safe)
                $query->where(function($q) use ($accessibleIds, $currentUser) {
                     $q->whereIn('dealership_id', $accessibleIds)
                       ->orWhereHas('assignments', function($subQ) use ($currentUser) {
                           $subQ->where('user_id', $currentUser->id);
                       })
                       ->orWhere('creator_id', $currentUser->id);
                });
            }
        } elseif ($dealershipId) {
            // Owner filtering by dealership
            $query->where('dealership_id', $dealershipId);
        }

        // Фильтрация по типу задачи
        if ($taskType) {
            $query->where('task_type', $taskType);
        }

        // Фильтрация по активности
        if ($isActive !== null) {
            $isActiveValue = filter_var($isActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActiveValue !== null) {
                $query->where('is_active', $isActiveValue);
            }
        }

        // Фильтрация по создателю
        if ($creatorId) {
            $query->where('creator_id', $creatorId);
        }

        // Фильтрация по типу ответа
        if ($responseType) {
            $query->where('response_type', $responseType);
        }

        // Фильтрация по тегам
        if ($tags) {
            $tagsArray = is_array($tags) ? $tags : explode(',', $tags);
            $tagsArray = array_map('trim', $tagsArray);
            $query->where(function ($q) use ($tagsArray) {
                foreach ($tagsArray as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }

        // Фильтрация по дедлайну
        if ($deadlineFrom) {
            try {
                $query->where('deadline', '>=', Carbon::parse($deadlineFrom));
            } catch (\Exception $e) {
                // Некорректная дата - игнорируем фильтр
            }
        }

        if ($deadlineTo) {
            try {
                $query->where('deadline', '<=', Carbon::parse($deadlineTo));
            } catch (\Exception $e) {
                // Некорректная дата - игнорируем фильтр
            }
        }

        // Фильтрация по наличию дедлайна
        if ($hasDeadline !== null) {
            $hasDeadlineValue = filter_var($hasDeadline, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($hasDeadlineValue !== null) {
                if ($hasDeadlineValue) {
                    $query->whereNotNull('deadline');
                } else {
                    $query->whereNull('deadline');
                }
            }
        }

        // Фильтрация по приоритету
        if ($priority) {
            $query->where('priority', $priority);
        }

        // Фильтрация по генератору задач
        if ($generatorId) {
            $query->where('generator_id', $generatorId);
        }

        // Фильтрация по источнику задачи (из генератора или нет)
        if ($fromGenerator === 'yes') {
            $query->whereNotNull('generator_id');
        } elseif ($fromGenerator === 'no') {
            $query->whereNull('generator_id');
        }

        // Поиск по названию, описанию, комментарию и тегам
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%")
                  ->orWhere('comment', 'ILIKE', "%{$search}%")
                  // Search in tags JSON array - cast to text for PostgreSQL
                  ->orWhereRaw("tags::text ILIKE ?", ["%{$search}%"]);
            });
        }

        // Фильтрация по статусу задачи (Bug #3 - код корректный, проверено)
        // Поддерживаемые статусы: active, completed, overdue, pending, acknowledged
        if ($status) {
            $now = Carbon::now();

            switch (strtolower($status)) {
                case 'active':
                    $query->where('is_active', true)
                          ->whereNull('archived_at');
                    break;

                case 'completed':
                    $query->whereHas('responses', function ($q) {
                        $q->where('status', 'completed');
                    });
                    break;

                case 'pending_review':
                    $query->whereHas('responses', function ($q) {
                        $q->where('status', 'pending_review');
                    });
                    break;

                case 'overdue':
                    $query->where('is_active', true)
                          ->whereNotNull('deadline')
                          ->where('deadline', '<', $now)
                          ->whereDoesntHave('responses', function ($q) {
                              $q->where('status', 'completed');
                          });
                    break;


                    break;

                case 'pending':
                    $query->where('is_active', true)
                          ->whereDoesntHave('responses', function ($q) {
                              $q->whereIn('status', ['completed', 'acknowledged', 'pending_review']);
                          });
                    break;

                case 'acknowledged':
                    $query->whereHas('responses', function ($q) {
                        $q->where('status', 'acknowledged');
                    });
                    break;
            }
        }

        // Исключаем архивные задачи (у которых есть archived_at)
        $query->whereNull('archived_at');

        $tasks = $query->orderByDesc('created_at')->paginate($perPage);

        // Transform tasks to use UTC+5 timezone
        $tasksData = $tasks->getCollection()->map(function ($task) {
            return $task->toApiArray();
        });

        return response()->json([
            'data' => $tasksData,
            'current_page' => $tasks->currentPage(),
            'last_page' => $tasks->lastPage(),
            'per_page' => $tasks->perPage(),
            'total' => $tasks->total(),
            'links' => [
                'first' => $tasks->url(1),
                'last' => $tasks->url($tasks->lastPage()),
                'prev' => $tasks->previousPageUrl(),
                'next' => $tasks->nextPageUrl(),
            ]
        ]);
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

        /** @var User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if ($currentUser->role !== Role::OWNER) {
            $accessibleIds = $currentUser->getAccessibleDealershipIds();

            // Check visibility: dealership match OR created by me OR assigned to me
            $isCreator = $task->creator_id === $currentUser->id;
            $isAssigned = $task->assignments->contains('user_id', $currentUser->id);
            $hasAccessToDealership = in_array($task->dealership_id, $accessibleIds);

            if (!$hasAccessToDealership && !$isCreator && !$isAssigned) {
                return response()->json([
                    'message' => 'У вас нет доступа к этой задаче'
                ], 403);
            }
        }

        return response()->json($task->toApiArray());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'required|string', // Bug #4: Removed after_or_equal:now constraint for flexibility, but made required
            'deadline' => 'required|string',
            'recurrence' => 'nullable|string|in:none,daily,weekly,monthly',
            'recurrence_time' => 'nullable|date_format:H:i', // Время для повторяющихся задач
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7', // 1=Пн, 7=Вс
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31', // -1=первое, -2=последнее, 1-31=число
            'task_type' => 'required|string|in:individual,group',
            'response_type' => 'required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
            'assignments' => 'nullable|array',
            'assignments.*' => 'exists:users,id',
            'notification_settings' => 'nullable|array',
            'priority' => 'nullable|string|in:low,medium,high',
        ]);

        // Custom validation for recurring tasks
        if (!empty($validated['recurrence']) && $validated['recurrence'] !== 'none') {
            switch ($validated['recurrence']) {
                case 'daily':
                    // Daily tasks require recurrence_time
                    if (empty($validated['recurrence_time'])) {
                        return response()->json([
                            'message' => 'Для ежедневных задач необходимо указать время (recurrence_time)'
                        ], 422);
                    }
                    break;
                case 'weekly':
                    // Weekly tasks require recurrence_day_of_week
                    if (empty($validated['recurrence_day_of_week'])) {
                        return response()->json([
                            'message' => 'Для еженедельных задач необходимо указать день недели (recurrence_day_of_week)'
                        ], 422);
                    }
                    break;
                case 'monthly':
                    // Monthly tasks require recurrence_day_of_month
                    if (empty($validated['recurrence_day_of_month'])) {
                        return response()->json([
                            'message' => 'Для ежемесячных задач необходимо указать число месяца (recurrence_day_of_month)'
                        ], 422);
                    }
                    break;
            }
        }

        // Security check: Ensure dealership is accessible
        /** @var User $currentUser */
        $currentUser = $request->user();
        if ($currentUser->role !== Role::OWNER) {
             $accessibleIds = $currentUser->getAccessibleDealershipIds();

             if (!empty($validated['dealership_id']) && !in_array($validated['dealership_id'], $accessibleIds)) {
                 return response()->json([
                    'message' => 'Вы не можете создать задачу в чужом автосалоне'
                ], 403);
             }
        }

        try {
            // Prevent duplicate tasks
            $duplicateQuery = Task::where('title', $validated['title'])
                ->where('task_type', $validated['task_type'])
                ->where('dealership_id', $validated['dealership_id'] ?? null)
                ->where('is_active', true);

            // Check deadline with tolerance (ignoring seconds mismatch)
            if (isset($validated['deadline'])) {
                // Parse the requested deadline. It might come as ISO string from frontend.
                // Note: The Task model mutator expects Asia/Yekaterinburg input usually,
                // but here we need to match what will be stored.
                // Let's rely on how the DB stores it. The strict equality check failed.
                // We should check if a task exists with the *same* minute.
                $deadlineDate = Carbon::parse($validated['deadline'], 'Asia/Yekaterinburg')->setTimezone('UTC');
                $start = $deadlineDate->copy()->startOfMinute();
                $end = $deadlineDate->copy()->endOfMinute();

                $duplicateQuery->whereBetween('deadline', [$start, $end]);
            } else {
                $duplicateQuery->whereNull('deadline');
            }

            if (isset($validated['description'])) {
                 $duplicateQuery->where('description', $validated['description']);
            } else {
                 $duplicateQuery->whereNull('description');
            }

            if ($duplicateQuery->exists()) {
                 return response()->json([
                    'message' => 'Такая задача уже существует (дубликат)'
                ], 422);
            }

            $task = Task::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'creator_id' => auth()->id(),
                'dealership_id' => $validated['dealership_id'] ?? null,
                'appear_date' => $validated['appear_date'] ?? null,
                'deadline' => $validated['deadline'] ?? null,
                'recurrence' => $validated['recurrence'] ?? null,
                'recurrence_time' => $validated['recurrence_time'] ?? null,
                'recurrence_day_of_week' => $validated['recurrence_day_of_week'] ?? null,
                'recurrence_day_of_month' => $validated['recurrence_day_of_month'] ?? null,
                'task_type' => $validated['task_type'],
                'response_type' => $validated['response_type'],
                'tags' => $validated['tags'] ?? null,
                'notification_settings' => $validated['notification_settings'] ?? null,
                'priority' => $validated['priority'] ?? 'medium',
            ]);

            // Assign users
            if (!empty($validated['assignments'])) {
                foreach ($validated['assignments'] as $userId) {
                    TaskAssignment::create([
                        'task_id' => $task->id,
                        'user_id' => $userId,
                    ]);
                }
            }

            return response()->json($task->load(['assignments.user'])->toApiArray(), 201);
        } catch (\Exception $e) {
             return response()->json([
                'message' => 'Ошибка при создании задачи',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        /** @var User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if ($currentUser->role !== Role::OWNER) {
            $accessibleIds = $currentUser->getAccessibleDealershipIds();

            // Check if current task is accessible
            if (!in_array($task->dealership_id, $accessibleIds) && $task->creator_id !== $currentUser->id) {
                 return response()->json([
                    'message' => 'У вас нет прав для редактирования этой задачи'
                ], 403);
            }
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'sometimes|required|string',
            'deadline' => 'sometimes|required|string',
            'recurrence' => 'nullable|string|in:none,daily,weekly,monthly',
            'recurrence_time' => 'nullable|date_format:H:i',
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'task_type' => 'sometimes|required|string|in:individual,group',
            'response_type' => 'sometimes|required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
            'is_active' => 'boolean',
            'assignments' => 'nullable|array',
            'assignments.*' => 'exists:users,id',
            'notification_settings' => 'nullable|array',
            'priority' => 'nullable|string|in:low,medium,high',
        ]);

        // Custom validation for recurring tasks
        $recurrence = $validated['recurrence'] ?? $task->recurrence;
        if (!empty($recurrence) && $recurrence !== 'none') {
            $recurrenceTime = $validated['recurrence_time'] ?? $task->recurrence_time;
            $recurrenceDayOfWeek = $validated['recurrence_day_of_week'] ?? $task->recurrence_day_of_week;
            $recurrenceDayOfMonth = $validated['recurrence_day_of_month'] ?? $task->recurrence_day_of_month;

            switch ($recurrence) {
                case 'daily':
                    if (empty($recurrenceTime)) {
                        return response()->json([
                            'message' => 'Для ежедневных задач необходимо указать время (recurrence_time)'
                        ], 422);
                    }
                    break;
                case 'weekly':
                    if (empty($recurrenceDayOfWeek)) {
                        return response()->json([
                            'message' => 'Для еженедельных задач необходимо указать день недели (recurrence_day_of_week)'
                        ], 422);
                    }
                    break;
                case 'monthly':
                    if (empty($recurrenceDayOfMonth)) {
                        return response()->json([
                            'message' => 'Для ежемесячных задач необходимо указать число месяца (recurrence_day_of_month)'
                        ], 422);
                    }
                    break;
            }
        }

        // Security check: Ensure new dealership is accessible
        if ($currentUser->role !== Role::OWNER) {
             $accessibleIds = $currentUser->getAccessibleDealershipIds();

             if (isset($validated['dealership_id']) && !in_array($validated['dealership_id'], $accessibleIds)) {
                 return response()->json([
                    'message' => 'Вы не можете перенести задачу в чужой автосалон'
                ], 403);
             }
        }

        $task->update($validated);

        // Update user assignments if provided
        if (array_key_exists('assignments', $validated)) {
            // Remove existing assignments
            TaskAssignment::where('task_id', $task->id)->delete();

            // Add new assignments
            if (!empty($validated['assignments'])) {
                foreach ($validated['assignments'] as $userId) {
                    TaskAssignment::create([
                        'task_id' => $task->id,
                        'user_id' => $userId,
                    ]);
                }
            }
        }

        return response()->json($task->load(['assignments.user', 'responses.user'])->toApiArray());
    }

    public function destroy($id)
    {
        $task = Task::find($id);

        if (!$task) {
             return response()->json([ // Fixed missing variable
                'message' => 'Задача не найдена'
            ], 404);
        }

        /** @var User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope
        if ($currentUser->role !== Role::OWNER) {
            $accessibleIds = $currentUser->getAccessibleDealershipIds();

            // Allow deletion if creator OR has access to dealership
            // Assuming Manager can delete tasks in their dealership even if not creator
            if (!in_array($task->dealership_id, $accessibleIds) && $task->creator_id !== $currentUser->id) {
                 return response()->json([
                    'message' => 'У вас нет прав для удаления этой задачи'
                ], 403);
            }
        }

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        // Delete task assignments (they will be automatically deleted due to foreign key constraints)
        TaskAssignment::where('task_id', $task->id)->delete();

        // Delete the task
        $task->delete();

        return response()->json([
            'message' => 'Задача успешно удалена'
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,pending_review,completed',
        ]);

        $status = $validated['status'];
        $user = auth()->user();

        switch ($status) {
            case 'pending':
                // Reset task: remove all responses
                $task->responses()->delete();
                break;

            case 'pending_review':
                // Update or create response with pending_review status
                $task->responses()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'status' => $status,
                        'responded_at' => Carbon::now(),
                    ]
                );
                break;

            case 'completed':
                // Update or create response for current user
                $task->responses()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'status' => $status,
                        'responded_at' => Carbon::now(),
                    ]
                );
                break;
        }

        return response()->json($task->refresh()->load(['assignments.user', 'responses.user'])->toApiArray());
    }
}
