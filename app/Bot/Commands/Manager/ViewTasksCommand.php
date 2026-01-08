<?php

declare(strict_types=1);

namespace App\Bot\Commands\Manager;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\Task;
use App\Traits\MaterialDesign3Trait;
use SergiX44\Nutgram\Nutgram;

/**
 * Command for managers to view tasks.
 * MD3: Task list with status summary chips.
 */
class ViewTasksCommand extends BaseCommandHandler
{
    use MaterialDesign3Trait;

    protected string $command = 'viewtasks';
    protected ?string $description = 'Просмотр задач';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get user's dealerships
        $dealershipIds = [$user->dealership_id];

        // Get tasks for manager's dealerships
        $tasks = Task::whereIn('dealership_id', $dealershipIds)
            ->where('is_active', true)
            ->with(['assignments.user', 'assignments.responses'])
            ->latest()
            ->take(10)
            ->get();

        $lines = [];
        $lines[] = '📋 *Задачи*';

        if ($tasks->isEmpty()) {
            $lines[] = '';
            $lines[] = 'Нет активных задач';
        } else {
            foreach ($tasks as $task) {
                $lines[] = '';
                $lines[] = "*{$task->title}*";

                // Count statuses
                $completed = 0;
                $acknowledged = 0;
                $pending = 0;

                foreach ($task->assignments as $assignment) {
                    $latestResponse = $assignment->responses->sortByDesc('created_at')->first();
                    if ($latestResponse) {
                        if ($latestResponse->status === 'completed') {
                            $completed++;
                        } elseif ($latestResponse->status === 'acknowledged') {
                            $acknowledged++;
                        } else {
                            $pending++;
                        }
                    } else {
                        $pending++;
                    }
                }

                $total = $task->assignments->count();
                $lines[] = "👥 {$total} · ✅ {$completed} · 👁️ {$acknowledged} · ⏳ {$pending}";
            }
        }

        $lines[] = '';
        $lines[] = '💡 Управление в веб-админке';

        $bot->sendMessage(implode("\n", $lines), parse_mode: 'Markdown');
    }
}
