<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ArchiveOldTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int|null $daysOverride Manual override for the number of days.
     */
    public function __construct(
        private readonly ?int $daysOverride = null
    ) {
    }

    public function handle(SettingsService $settingsService): void
    {
        try {
            $now = Carbon::now();
            $archivedCount = 0;

            $dealerships = \App\Models\AutoDealership::all();
            $dealerships->push(null); // Add null to handle global tasks

            foreach ($dealerships as $dealership) {
                $dealershipId = $dealership->id ?? null;

                $archiveDays = $this->daysOverride ?? $settingsService->getTaskArchiveDays($dealershipId);
                $archiveDate = $now->copy()->subDays($archiveDays);

                $tasksToArchive = Task::where('dealership_id', $dealershipId)
                    ->whereNull('archived_at')
                    ->where('created_at', '<', $archiveDate)
                    ->where(function ($query) {
                        $query->whereHas('responses', fn ($q) => $q->where('status', 'completed'))
                              ->orWhere('is_active', false);
                    })
                    ->whereDoesntHave('assignments.user', function ($query) {
                        // Exclude tasks that are not completed by all assigned users
                        $query->whereDoesntHave('taskResponses', fn($q) => $q->where('status', 'completed'));
                    })
                    ->update(['archived_at' => $now]);

                $archivedCount += $tasksToArchive;
            }

            Log::info('ArchiveOldTasksJob completed', ['archived_count' => $archivedCount]);
        } catch (\Throwable $e) {
            Log::error('ArchiveOldTasksJob failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }
}
