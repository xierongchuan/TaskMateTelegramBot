<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Employee;

use App\Bot\Abstracts\BaseConversation;
use App\Models\Shift;
use App\Models\User;
use App\Models\ShiftReplacement;
use App\Services\ShiftService;
use App\Traits\MaterialDesign3Trait;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for opening a shift with photo upload and optional replacement.
 *
 * Implements Material Design 3 principles:
 * - Step-by-step dialog flow with clear progress
 * - Semantic feedback for each action
 * - Consistent iconography and messaging patterns
 */
class OpenShiftConversation extends BaseConversation
{
    use MaterialDesign3Trait;

    protected ?string $photoPath = null;
    protected ?bool $isReplacement = null;
    protected ?int $replacedUserId = null;
    protected ?string $replacementReason = null;

    /**
     * Start: Ask for photo of computer screen with current time.
     * MD3: Step-by-step dialog with clear instructions.
     */
    public function start(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $shiftService = app(ShiftService::class);

            // Validate user belongs to a dealership
            if (!$shiftService->validateUserDealership($user)) {
                $bot->sendMessage(
                    '⚠️ Не привязаны к салону. Обратитесь к администратору.'
                );
                $this->end();
                return;
            }

            // Check if user already has an open shift
            $openShift = $shiftService->getUserOpenShift($user);

            if ($openShift) {
                $message = implode("\n", [
                    '⚠️ *Смена уже открыта*',
                    '',
                    '🕐 С ' . $openShift->shift_start->format('H:i d.m.Y'),
                ]);
                $bot->sendMessage($message, parse_mode: 'markdown');
                $this->end();
                return;
            }

            $message = implode("\n", [
                '📷 *Открытие смены*',
                '',
                'Загрузите фото экрана с текущим временем.',
            ]);

            $bot->sendMessage(
                $message,
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
     * MD3: Validation feedback with next step guidance.
     */
    public function handlePhoto(Nutgram $bot): void
    {
        try {
            // Handle skip button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'skip_photo') {
                $bot->answerCallbackQuery();
                // Ask replacement question without photo
                $bot->sendMessage(
                    '❓ Вы заменяете другого сотрудника?',
                    reply_markup: static::yesNoKeyboard()
                );
                $this->next('handleReplacementQuestion');
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
            $tempPath = sys_get_temp_dir() . '/shift_photo_' . uniqid() . '.jpg';
            $bot->downloadFile($file, $tempPath);

            if (!file_exists($tempPath)) {
                throw new \RuntimeException('Failed to download photo from Telegram');
            }

            // Store as UploadedFile for compatibility with ShiftService
            $this->photoPath = $tempPath;

            // Ask if replacing another employee
            $bot->sendMessage(
                '✅ Фото получено' . "\n\n" .
                '❓ Вы заменяете другого сотрудника?',
                reply_markup: static::yesNoKeyboard()
            );

            $this->next('handleReplacementQuestion');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handlePhoto');
        }
    }

    /**
     * Handle replacement question.
     * MD3: Binary choice dialog with clear navigation.
     */
    public function handleReplacementQuestion(Nutgram $bot): void
    {
        try {
            // Handle callback query if user somehow triggered one (shouldn't happen with ReplyKeyboard)
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
                $bot->sendMessage(
                    '⚠️ Используйте кнопки ниже.',
                    reply_markup: static::yesNoKeyboard()
                );
                $this->next('handleReplacementQuestion');
                return;
            }

            $answer = $bot->message()?->text;

            if (!$answer) {
                $bot->sendMessage(
                    '⚠️ Выберите ответ',
                    reply_markup: static::yesNoKeyboard()
                );
                $this->next('handleReplacementQuestion');
                return;
            }

            // Check for yes variants (with or without checkmark)
            if ($answer === '✓ Да' || $answer === 'Да') {
                $this->isReplacement = true;

                // Get list of employees from same dealership
                $user = $this->getAuthenticatedUser();
                $employees = User::where('dealership_id', $user->dealership_id)
                    ->where('role', 'employee')
                    ->where('id', '!=', $user->id)
                    ->get();

                if ($employees->isEmpty()) {
                    $bot->sendMessage(
                        '⚠️ Нет других сотрудников в салоне.',
                        reply_markup: static::removeKeyboard()
                    );
                    $this->createShift($bot);
                    return;
                }

                // Create employee list for selection keyboard
                $employeeList = $employees->map(fn($e) => [
                    'id' => $e->id,
                    'name' => $e->full_name
                ])->toArray();

                // First remove the reply keyboard, then show inline keyboard
                $bot->sendMessage('✓', reply_markup: static::removeKeyboard());
                $bot->sendMessage(
                    '👤 Кого заменяете?',
                    reply_markup: static::employeeSelectionKeyboard($employeeList)
                );

                $this->next('handleEmployeeSelection');
            } elseif ($answer === 'Нет') {
                $this->isReplacement = false;
                // Remove the reply keyboard before creating shift
                $bot->sendMessage('✓ Открываем смену...', reply_markup: static::removeKeyboard());
                $this->createShift($bot);
            } else {
                $bot->sendMessage(
                    '⚠️ Выберите ответ',
                    reply_markup: static::yesNoKeyboard()
                );
                $this->next('handleReplacementQuestion');
            }
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleReplacementQuestion');
        }
    }

    /**
     * Handle employee selection.
     * MD3: List selection with feedback.
     */
    public function handleEmployeeSelection(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()?->data;

            if (!$callbackData || !str_starts_with($callbackData, 'employee_')) {
                $bot->sendMessage('⚠️ Ошибка выбора. Попробуйте снова.');
                $this->end();
                return;
            }

            $this->replacedUserId = (int) str_replace('employee_', '', $callbackData);

            $bot->answerCallbackQuery('✓');
            $bot->sendMessage(
                '✍️ Укажите причину замены:',
                reply_markup: static::removeKeyboard()
            );

            $this->next('handleReplacementReason');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleEmployeeSelection');
        }
    }

    /**
     * Handle replacement reason.
     * MD3: Text input with validation.
     */
    public function handleReplacementReason(Nutgram $bot): void
    {
        try {
            $reason = $bot->message()?->text;

            if (!$reason || trim($reason) === '') {
                $bot->sendMessage('⚠️ Укажите причину замены.');
                $this->next('handleReplacementReason');
                return;
            }

            $this->replacementReason = trim($reason);

            $this->createShift($bot);
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleReplacementReason');
        }
    }

    /**
     * Create shift record using ShiftService.
     * MD3: Success card with comprehensive status display.
     */
    private function createShift(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $shiftService = app(ShiftService::class);

            // Create UploadedFile from the temporary photo path if available
            $uploadedFile = null;
            if ($this->photoPath && file_exists($this->photoPath)) {
                $uploadedFile = new UploadedFile(
                    $this->photoPath,
                    'shift_opening_photo.jpg',
                    'image/jpeg',
                    null,
                    true
                );
            }

            // Get replacement user if needed
            $replacingUser = null;
            if ($this->isReplacement && $this->replacedUserId) {
                $replacingUser = User::findOrFail($this->replacedUserId);

                // Validate replacement user belongs to the same dealership
                if (!$shiftService->validateUserDealership($replacingUser, $user->dealership_id)) {
                    $bot->sendMessage('⚠️ Сотрудник не из вашего салона.');
                    $this->end();
                    return;
                }
            }

            // Use ShiftService to create the shift
            $shift = $shiftService->openShift(
                $user,
                $uploadedFile,
                $replacingUser,
                $this->replacementReason
            );

            // Clean up temporary file
            if ($this->photoPath && file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }

            // Build success message with MD3 card pattern
            $now = Carbon::now();
            $lines = [];

            // Success header
            $lines[] = '✅ *Смена открыта*';
            $lines[] = '🕐 ' . $now->format('H:i d.m.Y');

            // Late status warning
            if ($shift->status === 'late') {
                $lines[] = '';
                $lines[] = '⚠️ Опоздание: ' . $shift->late_minutes . ' ' .
                    $this->pluralizeRu($shift->late_minutes, 'минута', 'минуты', 'минут');
            }

            // Replacement info
            if ($this->isReplacement && $replacingUser) {
                $lines[] = '';
                $lines[] = '📝 Замена: ' . $replacingUser->full_name;
                $lines[] = '💬 ' . $this->replacementReason;
            }

            // Schedule info
            if ($shift->scheduled_start && $shift->scheduled_end) {
                $lines[] = '';
                $lines[] = '📅 График: ' . $shift->scheduled_start->format('H:i') . ' – ' .
                    $shift->scheduled_end->format('H:i');
            }

            $bot->sendMessage(implode("\n", $lines), parse_mode: 'markdown', reply_markup: static::employeeMenu());

            // Send pending tasks
            $this->sendPendingTasks($bot, $user);

            $this->end();
        } catch (\Throwable $e) {
            // Clean up temporary file on error
            if ($this->photoPath && file_exists($this->photoPath)) {
                unlink($this->photoPath);
            }
            $this->handleError($bot, $e, 'createShift');
        }
    }


    /**
     * Send pending tasks to the employee.
     * MD3: List presentation with count summary.
     */
    private function sendPendingTasks(Nutgram $bot, User $user): void
    {
        try {
            $tasks = \App\Models\Task::whereHas('assignments', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('appear_date')
                    ->orWhere('appear_date', '<=', Carbon::now());
            })
            ->whereDoesntHave('responses', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('status', 'completed');
            })
            ->get();

            if ($tasks->isEmpty()) {
                $bot->sendMessage('✅ Нет активных задач');
                return;
            }

            $count = $tasks->count();
            $taskWord = $this->pluralizeRu($count, 'задача', 'задачи', 'задач');
            $bot->sendMessage("📋 *{$count} {$taskWord}*", parse_mode: 'markdown');

            foreach ($tasks as $task) {
                $this->sendTaskNotification($bot, $task, $user);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error sending tasks: ' . $e->getMessage());
        }
    }

    /**
     * Send task notification.
     * MD3: Task card with action button.
     */
    private function sendTaskNotification(Nutgram $bot, \App\Models\Task $task, User $user): void
    {
        $lines = [];

        // Title
        $lines[] = "📌 *{$task->title}*";

        // Description
        if ($task->description) {
            $lines[] = '';
            $lines[] = $task->description;
        }

        // Comment
        if ($task->comment) {
            $lines[] = '';
            $lines[] = "💬 {$task->comment}";
        }

        // Deadline
        if ($task->deadline) {
            $lines[] = '';
            $lines[] = "⏰ Дедлайн: {$task->deadline_for_bot}";
        }

        // Get keyboard using trait method
        $keyboard = static::getTaskKeyboard($task->response_type, $task->id);

        $bot->sendMessage(implode("\n", $lines), parse_mode: 'Markdown', reply_markup: $keyboard);
    }

    /**
     * Get default keyboard
     */
    protected function getDefaultKeyboard()
    {
        return static::employeeMenu();
    }
}
