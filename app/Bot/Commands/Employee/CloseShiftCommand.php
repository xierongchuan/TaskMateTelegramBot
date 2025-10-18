<?php

declare(strict_types=1);

namespace App\Bot\Commands\Employee;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\Shift;
use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use SergiX44\Nutgram\Nutgram;
use Carbon\Carbon;

/**
 * Command for employees to close their shift
 */
class CloseShiftCommand extends BaseCommandHandler
{
    protected string $command = 'closeshift';
    protected ?string $description = 'Закрыть смену';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Find open shift
        $openShift = Shift::where('user_id', $user->id)
            ->where('status', 'open')
            ->whereNull('shift_end')
            ->first();

        if (!$openShift) {
            $bot->sendMessage('⚠️ У вас нет открытой смены.');
            return;
        }

        // Close the shift
        $openShift->shift_end = Carbon::now();
        $openShift->status = 'closed';
        $openShift->save();

        // Log incomplete tasks
        $incompleteTasks = Task::whereHas('assignments', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->where('is_active', true)
        ->where(function ($query) use ($user) {
            $query->whereDoesntHave('responses', function ($subQuery) use ($user) {
                $subQuery->where('user_id', $user->id);
            })
            ->orWhereHas('responses', function ($subQuery) use ($user) {
                $subQuery->where('user_id', $user->id)
                         ->where('status', '!=', 'completed');
            });
        })
        ->get();

        $incompleteTasksCount = $incompleteTasks->count();

        foreach ($incompleteTasks as $task) {
            \App\Models\AuditLog::create([
                'user_id' => $user->id,
                'dealership_id' => $user->dealership_id,
                'action' => 'incomplete_task_on_shift_close',
                'details' => "Task #{$task->id} ('{$task->title}') was not completed.",
            ]);
        }

        $message = '✅ Смена закрыта в ' . Carbon::now()->format('H:i d.m.Y');

        if ($incompleteTasksCount > 0) {
            $message .= "\n\n⚠️ Незавершённых задач: " . $incompleteTasksCount;
        }

        $bot->sendMessage($message, reply_markup: static::employeeMenu());
    }
}
