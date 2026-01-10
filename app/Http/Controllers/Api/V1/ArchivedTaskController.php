<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Enums\Role;

class ArchivedTaskController extends Controller
{
    /**
     * List archived tasks with filtering.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Task::with(['creator', 'dealership', 'assignments.user', 'generator'])
            ->whereNotNull('archived_at');

        // Filter by dealership
        if ($request->has('dealership_id')) {
            $query->where('dealership_id', $request->dealership_id);
        } elseif ($user->role !== Role::OWNER) {
            $query->where('dealership_id', $user->dealership_id);
        }

        // Filter by archive reason (completed, expired)
        if ($request->has('archive_reason')) {
            $query->where('archive_reason', $request->archive_reason);
        }

        // Filter by generator
        if ($request->has('generator_id')) {
            $query->where('generator_id', $request->generator_id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $dateFrom = Carbon::parse($request->date_from, 'Asia/Yekaterinburg')->startOfDay();
            $query->where('archived_at', '>=', $dateFrom->setTimezone('UTC'));
        }

        if ($request->has('date_to')) {
            $dateTo = Carbon::parse($request->date_to, 'Asia/Yekaterinburg')->endOfDay();
            $query->where('archived_at', '<=', $dateTo->setTimezone('UTC'));
        }

        // Filter by assignee
        if ($request->has('assignee_id')) {
            $query->whereHas('assignments', function ($q) use ($request) {
                $q->where('user_id', $request->assignee_id);
            });
        }

        // Filter by tags
        if ($request->has('tags')) {
            $tags = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
            foreach ($tags as $tag) {
                $query->whereJsonContains('tags', trim($tag));
            }
        }

        // Search by title/description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', '%' . $search . '%')
                  ->orWhere('description', 'ilike', '%' . $search . '%');
            });
        }

        // Sorting
        $sortField = $request->get('sort_by', 'archived_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortField, $sortDir);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $tasks = $query->paginate($perPage);

        // Transform data
        $tasks->getCollection()->transform(fn($t) => $t->toApiArray());

        return response()->json($tasks);
    }

    /**
     * Restore a task from archive.
     */
    public function restore($id)
    {
        $task = Task::whereNotNull('archived_at')->findOrFail($id);

        // Restore the task
        $task->update([
            'is_active' => true,
            'archived_at' => null,
            'archive_reason' => null,
        ]);

        $task->load(['creator', 'dealership', 'assignments.user', 'generator']);

        return response()->json([
            'success' => true,
            'data' => $task->toApiArray(),
            'message' => 'Task restored from archive',
        ]);
    }

    /**
     * Export archived tasks to CSV.
     */
    public function export(Request $request)
    {
        $user = $request->user();

        $query = Task::with(['creator', 'dealership', 'assignments.user'])
            ->whereNotNull('archived_at');

        // Apply same filters as index
        if ($request->has('dealership_id')) {
            $query->where('dealership_id', $request->dealership_id);
        } elseif ($user->role !== Role::OWNER) {
            $query->where('dealership_id', $user->dealership_id);
        }

        if ($request->has('archive_reason')) {
            $query->where('archive_reason', $request->archive_reason);
        }

        if ($request->has('date_from')) {
            $dateFrom = Carbon::parse($request->date_from, 'Asia/Yekaterinburg')->startOfDay();
            $query->where('archived_at', '>=', $dateFrom->setTimezone('UTC'));
        }

        if ($request->has('date_to')) {
            $dateTo = Carbon::parse($request->date_to, 'Asia/Yekaterinburg')->endOfDay();
            $query->where('archived_at', '<=', $dateTo->setTimezone('UTC'));
        }

        $tasks = $query->orderBy('archived_at', 'desc')->get();

        // Generate CSV
        $csvContent = "ID,Title,Status,Archive Reason,Archived At,Dealership,Creator,Assignees\n";

        foreach ($tasks as $task) {
            $assignees = $task->assignments->pluck('user.full_name')->implode('; ');
            $archivedAt = $task->archived_at
                ? $task->archived_at->setTimezone('Asia/Yekaterinburg')->format('Y-m-d H:i')
                : '';

            $csvContent .= sprintf(
                "%d,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                $task->id,
                str_replace('"', '""', $task->title),
                $task->status ?? '',
                $task->archive_reason ?? '',
                $archivedAt,
                $task->dealership?->name ?? '',
                $task->creator?->full_name ?? '',
                str_replace('"', '""', $assignees)
            );
        }

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="archived_tasks_' . date('Y-m-d') . '.csv"',
        ]);
    }
}
