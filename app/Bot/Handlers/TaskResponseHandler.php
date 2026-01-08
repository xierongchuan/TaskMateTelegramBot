<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler for task response callbacks (OK, Done).
 *
 * MD3 Feedback patterns:
 * - Immediate visual feedback via toast (answerCallbackQuery)
 * - Button removal on success (clear completed action)
 * - Error state with helpful guidance
 */
class TaskResponseHandler
{
    /**
     * Handle task OK response (for notification-type tasks).
     * MD3: Acknowledge action with immediate feedback.
     */
    public static function handleOk(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()->data;
            $taskId = (int) str_replace('task_ok_', '', $callbackData);

            $user = auth()->user();
            if (!$user) {
                Log::warning('Task OK callback: User not authenticated', ['callback_data' => $callbackData]);
                $bot->answerCallbackQuery('⚠️ Войдите через /start', show_alert: true);
                return;
            }

            $task = Task::with('assignments')->find($taskId);
            if (!$task) {
                Log::warning('Task OK callback: Task not found', ['task_id' => $taskId, 'user_id' => $user->id]);
                $bot->answerCallbackQuery('⚠️ Задача не найдена', show_alert: true);
                return;
            }

            // Check if task is active
            if (!$task->is_active) {
                Log::info('Task OK callback: Task is not active', ['task_id' => $taskId, 'user_id' => $user->id]);
                $bot->answerCallbackQuery('⚠️ Задача неактивна', show_alert: true);
                return;
            }

            // Check if user is assigned to this task
            $isAssigned = $task->assignments()->where('user_id', $user->id)->exists();
            if (!$isAssigned) {
                Log::warning('Task OK callback: User not assigned to task', [
                    'task_id' => $taskId,
                    'user_id' => $user->id,
                    'task_title' => $task->title
                ]);
                $bot->answerCallbackQuery('⚠️ Вы не назначены', show_alert: true);
                return;
            }

            // Check if user already responded
            $existingResponse = TaskResponse::where('task_id', $taskId)
                ->where('user_id', $user->id)
                ->first();

            if ($existingResponse && $existingResponse->status === 'acknowledged') {
                $bot->answerCallbackQuery('ℹ️ Уже отмечено');
                return;
            }

            // Create or update response
            TaskResponse::updateOrCreate(
                [
                    'task_id' => $taskId,
                    'user_id' => $user->id,
                ],
                [
                    'status' => 'acknowledged',
                    'responded_at' => Carbon::now(),
                ]
            );

            // MD3: Clear success feedback
            $bot->answerCallbackQuery('✓ Принято');
            $bot->editMessageReplyMarkup(
                chat_id: $bot->chatId(),
                message_id: $bot->messageId(),
                reply_markup: null
            );

            Log::info('Task acknowledged successfully', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'task_title' => $task->title
            ]);
        } catch (\Throwable $e) {
            Log::error('Error handling task OK', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->user()?->id,
                'callback_data' => $bot->callbackQuery()?->data
            ]);
            $bot->answerCallbackQuery('⚠️ Ошибка. Попробуйте ещё раз', show_alert: true);
        }
    }

    /**
     * Handle task done response.
     * MD3: Completion action with success feedback.
     */
    public static function handleDone(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()->data;
            $taskId = (int) str_replace('task_done_', '', $callbackData);

            $user = auth()->user();
            if (!$user) {
                Log::warning('Task Done callback: User not authenticated', ['callback_data' => $callbackData]);
                $bot->answerCallbackQuery('⚠️ Войдите через /start', show_alert: true);
                return;
            }

            $task = Task::with('assignments')->find($taskId);
            if (!$task) {
                Log::warning('Task Done callback: Task not found', ['task_id' => $taskId, 'user_id' => $user->id]);
                $bot->answerCallbackQuery('⚠️ Задача не найдена', show_alert: true);
                return;
            }

            // Check if task is active
            if (!$task->is_active) {
                Log::info('Task Done callback: Task is not active', ['task_id' => $taskId, 'user_id' => $user->id]);
                $bot->answerCallbackQuery('⚠️ Задача неактивна', show_alert: true);
                return;
            }

            // Check if user is assigned to this task
            $isAssigned = $task->assignments()->where('user_id', $user->id)->exists();
            if (!$isAssigned) {
                Log::warning('Task Done callback: User not assigned to task', [
                    'task_id' => $taskId,
                    'user_id' => $user->id,
                    'task_title' => $task->title
                ]);
                $bot->answerCallbackQuery('⚠️ Вы не назначены', show_alert: true);
                return;
            }

            // For group tasks, check if someone already completed it
            if ($task->task_type === 'group') {
                $alreadyCompleted = TaskResponse::where('task_id', $taskId)
                    ->where('status', 'completed')
                    ->exists();

                if ($alreadyCompleted) {
                    $bot->answerCallbackQuery('ℹ️ Уже выполнено другим');
                    $bot->editMessageReplyMarkup(
                        chat_id: $bot->chatId(),
                        message_id: $bot->messageId(),
                        reply_markup: null
                    );
                    return;
                }
            }

            // Check if user already completed this task
            $existingResponse = TaskResponse::where('task_id', $taskId)
                ->where('user_id', $user->id)
                ->first();

            if ($existingResponse && $existingResponse->status === 'completed') {
                $bot->answerCallbackQuery('ℹ️ Уже отмечено');
                return;
            }

            // Create response
            TaskResponse::updateOrCreate(
                [
                    'task_id' => $taskId,
                    'user_id' => $user->id,
                ],
                [
                    'status' => 'completed',
                    'responded_at' => Carbon::now(),
                ]
            );

            // MD3: Success feedback
            $bot->answerCallbackQuery('✓ Выполнено');
            $bot->editMessageReplyMarkup(
                chat_id: $bot->chatId(),
                message_id: $bot->messageId(),
                reply_markup: null
            );

            Log::info('Task completed successfully', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'user_name' => $user->full_name,
                'task_title' => $task->title,
                'task_type' => $task->task_type
            ]);
        } catch (\Throwable $e) {
            Log::error('Error handling task done', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->user()?->id,
                'callback_data' => $bot->callbackQuery()?->data
            ]);
            $bot->answerCallbackQuery('⚠️ Ошибка. Попробуйте ещё раз', show_alert: true);
        }
    }
}
