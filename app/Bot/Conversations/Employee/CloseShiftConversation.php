<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Employee;

use App\Bot\Abstracts\BaseConversation;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use App\Services\ShiftService;
use App\Traits\MaterialDesign3Trait;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for closing a shift with photo upload and task logging.
 *
 * Implements Material Design 3 principles:
 * - Step-by-step dialog with progress indicators
 * - Clear status feedback cards
 * - Semantic messaging patterns
 */
class CloseShiftConversation extends BaseConversation
{
    use MaterialDesign3Trait;

    protected ?string $photoPath = null;
    protected ?Shift $shift = null;

    /**
     * Start: Check for open shift and request photo.
     * MD3: Status card with current shift info.
     */
    public function start(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $shiftService = app(ShiftService::class);

            // Validate user belongs to a dealership
            if (!$shiftService->validateUserDealership($user)) {
                $bot->sendMessage(
                    '⚠️ Не привязаны к салону. Обратитесь к администратору.',
                    reply_markup: static::employeeMenu()
                );
                $this->end();
                return;
            }

            // Find open shift using ShiftService
            $openShift = $shiftService->getUserOpenShift($user);

            if (!$openShift) {
                $bot->sendMessage('⚠️ Нет открытой смены', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $this->shift = $openShift;

            // Build shift info message with MD3 card pattern
            $lines = [];
            $lines[] = '🔒 *Закрытие смены*';
            $lines[] = '';
            $lines[] = '🕐 Открыта: ' . $openShift->shift_start->format('H:i d.m.Y');

            if ($openShift->status === 'late') {
                $lines[] = '⚠️ Опоздание: ' . $openShift->late_minutes . ' мин.';
            }

            $lines[] = '';
            $lines[] = '📷 Загрузите фото экрана.';

            $bot->sendMessage(
                implode("\n", $lines),
                parse_mode: 'markdown',
                reply_markup: static::photoUploadKeyboard('skip_photo', 'cancel')
            );

            $this->next('handlePhoto');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'start');
        }
    }

    /**
     * Handle photo upload.
     * MD3: Validation with clear next step.
     */
    public function handlePhoto(Nutgram $bot): void
    {
        try {
            // Handle skip button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'skip_photo') {
                $bot->answerCallbackQuery();
                $this->closeShift($bot);
                return;
            }

            // Handle cancel button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'cancel') {
                $bot->answerCallbackQuery();
                $bot->sendMessage('❌ Отменено', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $photo = $bot->message()?->photo;

            if (!$photo || empty($photo)) {
                $bot->sendMessage(
                    '⚠️ Отправьте фото или пропустите.',
                    reply_markup: static::photoUploadKeyboard('skip_photo', 'cancel')
                );
                $this->next('handlePhoto');
                return;
            }

            // Get the largest photo (best quality)
            $largestPhoto = end($photo);
            $fileId = $largestPhoto->file_id;

            // Download photo from Telegram
            $file = $bot->getFile($fileId);

            if (!$file || !$file->file_path) {
                throw new \RuntimeException('Failed to get file info from Telegram');
            }

            // Download file to temporary location
            $tempPath = sys_get_temp_dir() . '/shift_close_photo_' . uniqid() . '.jpg';
            $bot->downloadFile($file, $tempPath);

            if (!file_exists($tempPath)) {
                throw new \RuntimeException('Failed to download photo from Telegram');
            }

            // Store as UploadedFile for compatibility with ShiftService
            $this->photoPath = $tempPath;

            $bot->sendMessage('✓ Фото получено. Закрываем смену...');

            $this->closeShift($bot);
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handlePhoto');
        }
    }

    /**
     * Close the shift using ShiftService.
     * MD3: Success card with summary statistics.
     */
    private function closeShift(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $shiftService = app(ShiftService::class);

            if (!$this->shift) {
                $bot->sendMessage('⚠️ Смена не найдена', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $now = Carbon::now();
            $closingPhoto = null;

            // Create UploadedFile from the temporary photo path if provided
            if ($this->photoPath && file_exists($this->photoPath)) {
                $closingPhoto = new UploadedFile(
                    $this->photoPath,
                    'shift_closing_photo.jpg',
                    'image/jpeg',
                    null,
                    true
                );
            }

            // Use ShiftService to close the shift
            $shift = $shiftService->getUserOpenShift($user);
            if (!$shift) {
                $bot->sendMessage('⚠️ Нет открытой смены');
                return;
            }
            $updatedShift = $shiftService->closeShift($shift, $closingPhoto);

            // Clean up temporary file
            if ($this->photoPath && file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }

            // Calculate shift duration
            $duration = $updatedShift->shift_start->diffInMinutes($updatedShift->shift_end);
            $hours = floor($duration / 60);
            $minutes = $duration % 60;

            // Build success message with MD3 card pattern
            $lines = [];
            $lines[] = '✅ *Смена закрыта*';
            $lines[] = '🕐 ' . $now->format('H:i d.m.Y');
            $lines[] = '';
            $lines[] = "⏱️ Продолжительность: {$hours}ч {$minutes}м";

            if ($updatedShift->status === 'late') {
                $lines[] = '⚠️ Было опоздание';
            }

            // Find incomplete tasks using dealership context
            $incompleteTasks = Task::whereHas('assignments', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->orWhere('task_type', 'group') // Include group tasks
                ->where('dealership_id', $this->shift->dealership_id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('appear_date')
                        ->orWhere('appear_date', '<=', Carbon::now());
                })
                ->whereDoesntHave('responses', function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->whereIn('status', ['completed', 'acknowledged']);
                })
                ->get();

            if ($incompleteTasks->isNotEmpty()) {
                $count = $incompleteTasks->count();
                $taskWord = $this->pluralizeRu($count, 'задача', 'задачи', 'задач');
                $lines[] = '';
                $lines[] = "⚠️ *Незавершено: {$count} {$taskWord}*";

                // List incomplete tasks
                foreach ($incompleteTasks as $task) {
                    $taskLine = "• {$task->title}";
                    if ($task->deadline) {
                        $taskLine .= " ⏰ {$task->deadline->format('d.m H:i')}";
                    }
                    $lines[] = $taskLine;
                }

                // Notify managers about incomplete tasks
                $this->notifyManagersAboutIncompleteTasks($bot, $user, $incompleteTasks);
            } else {
                $lines[] = '';
                $lines[] = '✅ Все задачи выполнены';
            }

            $bot->sendMessage(implode("\n", $lines), parse_mode: 'Markdown', reply_markup: static::employeeMenu());

            \Illuminate\Support\Facades\Log::info(
                "Shift closed by user #{$user->id} in dealership #{$this->shift->dealership_id}, " .
                "duration: {$duration} minutes, incomplete tasks: " . $incompleteTasks->count()
            );

            $this->end();
        } catch (\Throwable $e) {
            // Clean up temporary file on error
            if ($this->photoPath && file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }
            $this->handleError($bot, $e, 'closeShift');
        }
    }

    /**
     * Notify managers about incomplete tasks when shift closes.
     * MD3: Alert notification to managers.
     */
    private function notifyManagersAboutIncompleteTasks(Nutgram $bot, User $user, $incompleteTasks): void
    {
        try {
            // Find managers for this dealership
            $managers = User::where('dealership_id', $user->dealership_id)
                ->whereIn('role', ['manager', 'owner'])
                ->whereNotNull('telegram_id')
                ->get();

            foreach ($managers as $manager) {
                $count = $incompleteTasks->count();
                $taskWord = $this->pluralizeRu($count, 'задача', 'задачи', 'задач');

                $lines = [];
                $lines[] = '⚠️ *Незавершённые задачи*';
                $lines[] = '';
                $lines[] = "👤 {$user->full_name}";
                $lines[] = '🕐 ' . Carbon::now()->format('H:i d.m.Y');
                $lines[] = '';
                $lines[] = "*{$count} {$taskWord}:*";

                foreach ($incompleteTasks as $task) {
                    $taskLine = "• {$task->title}";
                    if ($task->deadline) {
                        $taskLine .= " ⏰ {$task->deadline->format('d.m H:i')}";
                    }
                    $lines[] = $taskLine;
                }

                try {
                    $bot->sendMessage(
                        text: implode("\n", $lines),
                        chat_id: $manager->telegram_id,
                        parse_mode: 'Markdown'
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Failed to notify manager #{$manager->id}: " . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error notifying managers: ' . $e->getMessage());
        }
    }

    /**
     * Get default keyboard
     */
    protected function getDefaultKeyboard()
    {
        return static::employeeMenu();
    }
}
