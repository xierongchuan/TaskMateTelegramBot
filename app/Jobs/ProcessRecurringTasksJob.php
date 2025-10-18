<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskAssignment;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRecurringTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            $now = Carbon::now();
            $templateTasks = Task::where('is_active', true)
                ->whereNotNull('recurrence')
                ->get();

            foreach ($templateTasks as $template) {
                if ($this->shouldCreateNewInstance($template, $now)) {
                    $this->createTaskInstance($template, $now);
                }
            }

            Log::info('ProcessRecurringTasksJob completed', ['tasks_processed' => $templateTasks->count()]);
        } catch (\Throwable $e) {
            Log::error('ProcessRecurringTasksJob failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    private function shouldCreateNewInstance(Task $template, Carbon $now): bool
    {
        // 1. Check if an instance for today already exists
        $alreadyCreated = Task::where('original_task_id', $template->id)
            ->whereDate('created_at', $now->toDateString())
            ->exists();

        if ($alreadyCreated) {
            return false;
        }

        // 2. Check the recurrence rule
        return match ($template->recurrence) {
            'daily' => true,
            'weekly' => $now->dayOfWeek === $template->created_at->dayOfWeek,
            'monthly' => $now->day === $template->created_at->day,
            default => false,
        };
    }

    private function createTaskInstance(Task $template, Carbon $now): void
    {
        try {
            $newTask = Task::create([
                'title' => $template->title,
                'description' => $template->description,
                'comment' => $template->comment,
                'creator_id' => $template->creator_id,
                'dealership_id' => $template->dealership_id,
                'appear_date' => $now,
                'deadline' => $template->deadline ? $now->copy()->setTimeFrom($template->deadline) : null,
                'recurrence' => null,
                'task_type' => $template->task_type,
                'response_type' => $template->response_type,
                'tags' => $template->tags,
                'is_active' => true,
                'original_task_id' => $template->id,
            ]);

            foreach ($template->assignments as $assignment) {
                TaskAssignment::create([
                    'task_id' => $newTask->id,
                    'user_id' => $assignment->user_id,
                ]);
            }

            Log::info('Created recurring task instance', [
                'template_task_id' => $template->id,
                'new_task_id' => $newTask->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create recurring task instance', [
                'task_id' => $template->id,
                'exception' => $e,
            ]);
        }
    }
}
