<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveCompletedTasks extends Command
{
    protected $signature = 'tasks:archive-completed
                            {--type=all : Type of tasks to archive: completed, overdue, or all}
                            {--force : Force archiving even if not the configured day/time}';

    protected $description = 'Archive completed and/or overdue tasks based on settings';

    public function handle(SettingsService $settingsService): int
    {
        $type = $this->option('type');
        $force = $this->option('force');
        $now = Carbon::now('Asia/Yekaterinburg');
        $todayDayOfWeek = $now->dayOfWeek === 0 ? 7 : $now->dayOfWeek; // 1-7 (Mon-Sun)
        $currentTime = $now->format('H:i');

        $this->info("Current time: {$now->format('Y-m-d H:i:s')} (Day: $todayDayOfWeek)");
        $this->info("Archive type: $type");

        $archivedCompleted = 0;
        $archivedOverdue = 0;

        // Get dealership-specific settings
        $dealershipSettings = DB::table('settings')
            ->whereIn('key', ['archive_completed_time', 'archive_overdue_day_of_week', 'archive_overdue_time'])
            ->whereNotNull('dealership_id')
            ->get()
            ->groupBy('dealership_id');

        // Get global settings
        $globalCompletedTime = $settingsService->get('archive_completed_time') ?? '03:00';
        $globalOverdueDayOfWeek = (int) ($settingsService->get('archive_overdue_day_of_week') ?? 0);
        $globalOverdueTime = $settingsService->get('archive_overdue_time') ?? '03:00';

        // Process dealership-specific archiving
        $processedDealerships = [];
        foreach ($dealershipSettings as $dealershipId => $settings) {
            $settingsMap = $settings->pluck('value', 'key')->toArray();

            $completedTime = $settingsMap['archive_completed_time'] ?? $globalCompletedTime;
            $overdueDayOfWeek = (int) ($settingsMap['archive_overdue_day_of_week'] ?? $globalOverdueDayOfWeek);
            $overdueTime = $settingsMap['archive_overdue_time'] ?? $globalOverdueTime;

            // Archive completed tasks (daily at configured time)
            if (in_array($type, ['completed', 'all'])) {
                if ($force || $this->isTimeMatch($currentTime, $completedTime)) {
                    $count = $this->archiveCompletedTasks((int) $dealershipId);
                    $archivedCompleted += $count;
                    if ($count > 0) {
                        $this->info("  Dealership $dealershipId: Archived $count completed tasks");
                    }
                }
            }

            // Archive overdue tasks (weekly at configured day/time)
            if (in_array($type, ['overdue', 'all'])) {
                if ($overdueDayOfWeek > 0) {
                    if ($force || ($todayDayOfWeek === $overdueDayOfWeek && $this->isTimeMatch($currentTime, $overdueTime))) {
                        $count = $this->archiveOverdueTasks((int) $dealershipId);
                        $archivedOverdue += $count;
                        if ($count > 0) {
                            $this->info("  Dealership $dealershipId: Archived $count overdue tasks");
                        }
                    }
                }
            }

            $processedDealerships[] = $dealershipId;
        }

        // Process tasks for dealerships without specific settings (use global settings)
        $remainingDealerships = DB::table('tasks')
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->whereNotNull('dealership_id')
            ->whereNotIn('dealership_id', $processedDealerships)
            ->distinct()
            ->pluck('dealership_id');

        foreach ($remainingDealerships as $dealershipId) {
            // Archive completed tasks (daily at configured time)
            if (in_array($type, ['completed', 'all'])) {
                if ($force || $this->isTimeMatch($currentTime, $globalCompletedTime)) {
                    $count = $this->archiveCompletedTasks((int) $dealershipId);
                    $archivedCompleted += $count;
                    if ($count > 0) {
                        $this->info("  Dealership $dealershipId: Archived $count completed tasks (global settings)");
                    }
                }
            }

            // Archive overdue tasks (weekly at configured day/time)
            if (in_array($type, ['overdue', 'all'])) {
                if ($globalOverdueDayOfWeek > 0) {
                    if ($force || ($todayDayOfWeek === $globalOverdueDayOfWeek && $this->isTimeMatch($currentTime, $globalOverdueTime))) {
                        $count = $this->archiveOverdueTasks((int) $dealershipId);
                        $archivedOverdue += $count;
                        if ($count > 0) {
                            $this->info("  Dealership $dealershipId: Archived $count overdue tasks (global settings)");
                        }
                    }
                }
            }
        }

        // Summary
        $totalArchived = $archivedCompleted + $archivedOverdue;
        if ($totalArchived > 0) {
            $this->info("Total archived: $archivedCompleted completed, $archivedOverdue overdue tasks");
            Log::info("Auto-archived tasks", [
                'completed' => $archivedCompleted,
                'overdue' => $archivedOverdue,
            ]);
        } else {
            $this->info("No tasks to archive");
        }

        return Command::SUCCESS;
    }

    /**
     * Check if current time matches configured time (with 5 minute tolerance)
     */
    private function isTimeMatch(string $currentTime, string $configuredTime): bool
    {
        $current = Carbon::createFromFormat('H:i', $currentTime, 'Asia/Yekaterinburg');
        $configured = Carbon::createFromFormat('H:i', $configuredTime, 'Asia/Yekaterinburg');

        return abs($current->diffInMinutes($configured)) <= 5;
    }

    /**
     * Archive completed tasks for a dealership
     */
    private function archiveCompletedTasks(?int $dealershipId): int
    {
        $cutoffDate = Carbon::now('Asia/Yekaterinburg')->subDay();

        $query = Task::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereHas('responses', function ($q) {
                $q->where('status', 'completed');
            });

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        $tasks = $query->with('responses')->get();
        $archivedCount = 0;

        foreach ($tasks as $task) {
            $lastResponse = $task->responses()
                ->where('status', 'completed')
                ->orderBy('created_at', 'desc')
                ->first();

            // Only archive if completed more than 1 day ago
            if ($lastResponse && Carbon::parse($lastResponse->created_at)->lt($cutoffDate)) {
                $task->update([
                    'is_active' => false,
                    'archived_at' => Carbon::now(),
                    'archive_reason' => 'completed',
                ]);
                $archivedCount++;
            }
        }

        return $archivedCount;
    }

    /**
     * Archive overdue tasks for a dealership
     */
    private function archiveOverdueTasks(?int $dealershipId): int
    {
        $cutoffDate = Carbon::now('Asia/Yekaterinburg')->subDay();

        $query = Task::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereNotNull('deadline')
            ->where('deadline', '<', $cutoffDate)
            ->whereDoesntHave('responses', function ($q) {
                $q->where('status', 'completed');
            });

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        $archivedCount = 0;

        foreach ($query->get() as $task) {
            $task->update([
                'is_active' => false,
                'archived_at' => Carbon::now(),
                'archive_reason' => 'expired',
            ]);
            $archivedCount++;
        }

        return $archivedCount;
    }
}
