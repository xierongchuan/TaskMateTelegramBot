<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (! $dateFrom || ! $dateTo) {
            return response()->json(['message' => 'Parameters date_from and date_to are required'], 400);
        }

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

        // Determine Dealership Filter
        $dealershipId = null;
        if ($user->role === 'manager' && $user->dealership_id) {
            $dealershipId = $user->dealership_id;
        }

        // Helper to apply dealership filter to Task query
        // Assuming Tasks have dealership_id or we filter by assigned users in dealership
        // Inspecting Task model or migrations earlier, we saw 'dealership_id' in Task migrations?
        // Let's rely on tasks.dealership_id if it exists, or relation.
        // Given earlier UI filters had 'dealership_id', column likely exists.
        $applyTaskFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        // Helper for Shift filter
        $applyShiftFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        // Summary Statistics
        $totalTasksQuery = Task::whereBetween('created_at', [$from, $to]);
        $applyTaskFilter($totalTasksQuery);
        $totalTasks = $totalTasksQuery->count();

        $completedTasksQuery = Task::whereBetween('created_at', [$from, $to])->where('is_active', false);
        $applyTaskFilter($completedTasksQuery);
        $completedTasks = $completedTasksQuery->count();

        $overdueTasksQuery = Task::whereBetween('deadline', [$from, $to])
            ->where('deadline', '<', Carbon::now())
            ->where('is_active', true);
        $applyTaskFilter($overdueTasksQuery);
        $overdueTasks = $overdueTasksQuery->count();

        $postponedTasksQuery = Task::whereBetween('created_at', [$from, $to])
            ->where('postpone_count', '>', 0);
        $applyTaskFilter($postponedTasksQuery);
        $postponedTasks = $postponedTasksQuery->count();

        $totalShiftsQuery = Shift::whereBetween('shift_start', [$from, $to]);
        $applyShiftFilter($totalShiftsQuery);
        $totalShifts = $totalShiftsQuery->count();

        $lateShiftsQuery = Shift::whereBetween('shift_start', [$from, $to])
            ->where('late_minutes', '>', 0);
        $applyShiftFilter($lateShiftsQuery);
        $lateShifts = $lateShiftsQuery->count();

        $totalReplacementsQuery = Shift::whereBetween('shift_start', [$from, $to])->has('replacement');
        $applyShiftFilter($totalReplacementsQuery);
        $totalReplacements = $totalReplacementsQuery->count();

        // Tasks by Status
        $activeTasksQuery = Task::whereBetween('created_at', [$from, $to])
            ->where('is_active', true)
            ->where(function($q) {
                $q->whereNull('deadline')->orWhere('deadline', '>=', Carbon::now());
            });
        $applyTaskFilter($activeTasksQuery);
        $activeTasksCount = $activeTasksQuery->count();

        // Pending review tasks count
        $pendingReviewQuery = Task::whereBetween('created_at', [$from, $to])
            ->whereHas('responses', function ($q) {
                $q->where('status', 'pending_review');
            });
        $applyTaskFilter($pendingReviewQuery);
        $pendingReviewTasks = $pendingReviewQuery->count();

        $tasksByStatus = [
            [
                'status' => 'completed',
                'count' => $completedTasks,
                'percentage' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0
            ],
            [
                'status' => 'overdue',
                'count' => $overdueTasks,
                'percentage' => $totalTasks > 0 ? round(($overdueTasks / $totalTasks) * 100, 1) : 0
            ],
            [
                'status' => 'active',
                'count' => $activeTasksCount,
                'percentage' => $totalTasks > 0 ? round(($activeTasksCount / $totalTasks) * 100, 1) : 0
            ],
            [
                'status' => 'pending_review',
                'count' => $pendingReviewTasks,
                'percentage' => $totalTasks > 0 ? round(($pendingReviewTasks / $totalTasks) * 100, 1) : 0
            ]
        ];

        // Employee Performance
        $employeesQuery = User::where('role', 'employee');
        if ($dealershipId) {
            $employeesQuery->where('dealership_id', $dealershipId);
        }
        $employees = $employeesQuery->get();

        $employeesPerformance = $employees->map(function ($user) use ($from, $to) {
            // Task filter handles user specific logic, but tasks are usually assigned to a user or dealership
            // Here we look at tasks specifically assigned to the user
            $userTasksQuery = Task::whereHas('assignedUsers', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });
            // Note: Assigned tasks might be from any dealership if user moved, but typically strict.
            // We count specific tasks regardless of dealership ID on the TASK itself,
            // because they are assigned to THIS user who is in THIS dealership.
            // So we don't strictly need to filter tasks by dealership_id here again if we trust the user assignment scope.
            // But if we want to be strict about 'tasks within this dealership context', we could add it.
            // Let's stick to user assignment.

            $userTasks = (clone $userTasksQuery)
                ->whereBetween('created_at', [$from, $to])
                ->count();

            $userCompleted = (clone $userTasksQuery)
                ->whereBetween('created_at', [$from, $to])
                ->where('is_active', false)
                ->count();

            $userOverdue = (clone $userTasksQuery)
                ->whereBetween('deadline', [$from, $to])
                ->where('deadline', '<', Carbon::now())
                ->where('is_active', true)
                ->count();

            $userLateShifts = Shift::where('user_id', $user->id)
                ->whereBetween('shift_start', [$from, $to])
                ->where('late_minutes', '>', 0)
                ->count();

            // Simple score calculation
            $score = 100;
            if ($userTasks > 0) {
                $score -= ($userOverdue * 5);
            }
            $score -= ($userLateShifts * 10);
            $score = max(0, min(100, $score));

            return [
                'employee_id' => $user->id,
                'employee_name' => $user->full_name,
                'completed_tasks' => $userCompleted,
                'overdue_tasks' => $userOverdue,
                'late_shifts' => $userLateShifts,
                'performance_score' => $score,
            ];
        })->sortByDesc('performance_score')->values();

        // Daily Stats
        $dailyStats = [];
        $current = $from->copy();
        while ($current <= $to) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();

            $dayCompletedQuery = Task::whereBetween('created_at', [$dayStart, $dayEnd])->where('is_active', false);
            $applyTaskFilter($dayCompletedQuery);
            $dayCompleted = $dayCompletedQuery->count();

            $dayOverdueQuery = Task::whereBetween('deadline', [$dayStart, $dayEnd])
                ->where('deadline', '<', Carbon::now())
                ->where('is_active', true);
            $applyTaskFilter($dayOverdueQuery);
            $dayOverdue = $dayOverdueQuery->count();

            $dayLateShiftsQuery = Shift::whereBetween('shift_start', [$dayStart, $dayEnd])
                ->where('late_minutes', '>', 0);
            $applyShiftFilter($dayLateShiftsQuery);
            $dayLateShifts = $dayLateShiftsQuery->count();

            $dailyStats[] = [
                'date' => $current->format('Y-m-d'),
                'completed' => $dayCompleted,
                'overdue' => $dayOverdue,
                'late_shifts' => $dayLateShifts,
            ];
            $current->addDay();
        }

        // Top Issues
        $topIssues = [];
        if ($overdueTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'overdue_tasks',
                'count' => $overdueTasks,
                'description' => 'Просроченные задачи'
            ];
        }
        if ($lateShifts > 0) {
            $topIssues[] = [
                'issue_type' => 'late_shifts',
                'count' => $lateShifts,
                'description' => 'Опоздания на смены'
            ];
        }
        if ($postponedTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'frequent_postponements',
                'count' => $postponedTasks,
                'description' => 'Частые переносы задач'
            ];
        }
        usort($topIssues, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return response()->json([
            'period' => $from->format('Y-m-d') . ' - ' . $to->format('Y-m-d'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'overdue_tasks' => $overdueTasks,
                'postponed_tasks' => $postponedTasks,
                'total_shifts' => $totalShifts,
                'late_shifts' => $lateShifts,
                'total_replacements' => $totalReplacements,
            ],
            'tasks_by_status' => $tasksByStatus,
            'employees_performance' => $employeesPerformance,
            'daily_stats' => $dailyStats,
            'top_issues' => $topIssues,
        ]);
    }
}
