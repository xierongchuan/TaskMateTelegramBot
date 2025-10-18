<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Employee;

use App\Bot\Abstracts\BaseConversation;
use App\Models\Shift;
use App\Models\User;
use App\Models\ShiftReplacement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

/**
 * Conversation for opening a shift with photo upload and optional replacement
 */
class OpenShiftConversation extends BaseConversation
{
    protected ?string $photoPath = null;
    protected ?bool $isReplacement = null;
    protected ?int $replacedUserId = null;
    protected ?string $replacementReason = null;

    /**
     * Start: Ask for photo of computer screen with current time
     */
    public function start(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();

            // Check if user already has an open shift
            $openShift = Shift::where('user_id', $user->id)
                ->where('status', 'open')
                ->whereNull('shift_end')
                ->first();

            if ($openShift) {
                $bot->sendMessage(
                    '⚠️ У вас уже есть открытая смена с ' .
                    $openShift->shift_start->format('H:i d.m.Y')
                );
                $this->end();
                return;
            }

            $bot->sendMessage(
                '📸 Пожалуйста, загрузите фото экрана компьютера с текущим временем для открытия смены.',
                reply_markup: \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                    ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '❌ Отменить',
                        callback_data: 'cancel'
                    ))
            );

            $this->next('handlePhoto');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'start');
        }
    }

    /**
     * Handle photo upload
     */
    public function handlePhoto(Nutgram $bot): void
    {
        try {
            // Handle cancel button
            if ($bot->callbackQuery() && $bot->callbackQuery()->data === 'cancel') {
                $bot->answerCallbackQuery();
                $bot->sendMessage('❌ Открытие смены отменено.', reply_markup: static::employeeMenu());
                $this->end();
                return;
            }

            $photo = $bot->message()?->photo;

            if (!$photo || empty($photo)) {
                $bot->sendMessage(
                    '⚠️ Пожалуйста, отправьте фото.\n\n' .
                    'Или нажмите кнопку "Отменить" для выхода.',
                    reply_markup: \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                        ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                            text: '❌ Отменить',
                            callback_data: 'cancel'
                        ))
                );
                $this->next('handlePhoto');
                return;
            }

            // Get the largest photo (best quality)
            $largestPhoto = end($photo);
            $fileId = $largestPhoto->file_id;

            // Download photo from Telegram
            $file = $bot->getFile($fileId);
            $filePath = $file->file_path;

            // Download file content
            $fileContent = file_get_contents("https://api.telegram.org/file/bot{$bot->getConfig()['token']}/{$filePath}");

            if ($fileContent === false) {
                throw new \RuntimeException('Failed to download photo');
            }

            // Save photo to storage
            $filename = 'shifts/' . uniqid('shift_photo_', true) . '.jpg';
            Storage::disk('public')->put($filename, $fileContent);

            $this->photoPath = $filename;

            // Ask if replacing another employee
            $bot->sendMessage(
                '✅ Фото получено.\n\n' .
                '❓ Вы заменяете другого сотрудника?',
                reply_markup: static::yesNoKeyboard('Да', 'Нет')
            );

            $this->next('handleReplacementQuestion');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handlePhoto');
        }
    }

    /**
     * Handle replacement question
     */
    public function handleReplacementQuestion(Nutgram $bot): void
    {
        try {
            $answer = $bot->message()?->text;

            if ($answer === 'Да') {
                $this->isReplacement = true;

                // Get list of employees from same dealership
                $user = $this->getAuthenticatedUser();
                $employees = User::where('dealership_id', $user->dealership_id)
                    ->where('role', 'employee')
                    ->where('id', '!=', $user->id)
                    ->get();

                if ($employees->isEmpty()) {
                    $bot->sendMessage(
                        '⚠️ Не найдено других сотрудников в вашем салоне.',
                        reply_markup: static::removeKeyboard()
                    );
                    $this->createShift($bot);
                    return;
                }

                // Create inline keyboard with employee list
                $buttons = [];
                foreach ($employees as $employee) {
                    $buttons[] = [
                        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                            text: $employee->full_name,
                            callback_data: 'employee_' . $employee->id
                        )
                    ];
                }

                $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
                foreach ($buttons as $row) {
                    $keyboard->addRow(...$row);
                }

                $bot->sendMessage(
                    '👤 Выберите сотрудника, которого вы заменяете:',
                    reply_markup: $keyboard
                );

                $this->next('handleEmployeeSelection');
            } elseif ($answer === 'Нет') {
                $this->isReplacement = false;
                $this->createShift($bot);
            } else {
                $bot->sendMessage(
                    '⚠️ Пожалуйста, выберите "Да" или "Нет"',
                    reply_markup: static::yesNoKeyboard('Да', 'Нет')
                );
                $this->next('handleReplacementQuestion');
            }
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleReplacementQuestion');
        }
    }

    /**
     * Handle employee selection
     */
    public function handleEmployeeSelection(Nutgram $bot): void
    {
        try {
            $callbackData = $bot->callbackQuery()?->data;

            if (!$callbackData || !str_starts_with($callbackData, 'employee_')) {
                $bot->sendMessage('⚠️ Ошибка выбора сотрудника. Попробуйте снова.');
                $this->end();
                return;
            }

            $this->replacedUserId = (int) str_replace('employee_', '', $callbackData);

            $bot->answerCallbackQuery();
            $bot->sendMessage(
                '✍️ Укажите причину замещения:',
                reply_markup: static::removeKeyboard()
            );

            $this->next('handleReplacementReason');
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'handleEmployeeSelection');
        }
    }

    /**
     * Handle replacement reason
     */
    public function handleReplacementReason(Nutgram $bot): void
    {
        try {
            $reason = $bot->message()?->text;

            if (!$reason || trim($reason) === '') {
                $bot->sendMessage('⚠️ Пожалуйста, укажите причину замещения.');
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
     * Create shift record
     */
    private function createShift(Nutgram $bot): void
    {
        try {
            $user = $this->getAuthenticatedUser();
            $now = Carbon::now();
            $settingsService = app(\App\Services\SettingsService::class);

            // Determine which shift (1 or 2) based on current time
            $shiftNumber = $this->determineShiftNumber($now, $settingsService, $user->dealership_id);

            // Get scheduled start time for this shift
            $scheduledStartTime = $settingsService->getShiftStartTime($user->dealership_id, $shiftNumber);
            $scheduledStart = Carbon::parse($now->format('Y-m-d') . ' ' . $scheduledStartTime);

            // Calculate late minutes
            $lateMinutes = 0;
            $status = 'open';
            $lateTolerance = $settingsService->getLateTolerance($user->dealership_id);

            if ($now->greaterThan($scheduledStart->addMinutes($lateTolerance))) {
                $lateMinutes = $scheduledStart->diffInMinutes($now);
                $status = 'late';
            }

            // Create shift
            $shift = Shift::create([
                'user_id' => $user->id,
                'dealership_id' => $user->dealership_id,
                'shift_start' => $now,
                'opening_photo_path' => $this->photoPath,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'scheduled_start' => $scheduledStart,
            ]);

            // Create replacement record if needed
            if ($this->isReplacement && $this->replacedUserId) {
                ShiftReplacement::create([
                    'shift_id' => $shift->id,
                    'replacing_user_id' => $user->id,
                    'replaced_user_id' => $this->replacedUserId,
                    'reason' => $this->replacementReason,
                ]);

                // Notify managers about replacement
                $replacedUser = User::find($this->replacedUserId);
                if ($replacedUser) {
                    $managerService = app(\App\Services\ManagerNotificationService::class);
                    $managerService->notifyAboutReplacement($shift, $user, $replacedUser, $this->replacementReason);
                }
            }

            // Send welcome message and tasks
            $message = "✅ Смена открыта в " . $now->format('H:i d.m.Y') . "\n\n";
            $message .= "👋 Приветствие!\n\n";

            if ($this->isReplacement) {
                $replacedUser = User::find($this->replacedUserId);
                $message .= "📝 Вы заменяете: {$replacedUser->full_name}\n";
                $message .= "💬 Причина: {$this->replacementReason}\n\n";
            }

            $bot->sendMessage($message, reply_markup: static::employeeMenu());

            // Notify managers if late
            if ($status === 'late') {
                $managerService = app(\App\Services\ManagerNotificationService::class);
                $managerService->notifyAboutLateShift($shift);
            }

            // Send pending tasks
            $this->sendPendingTasks($bot, $user);

            $this->end();
        } catch (\Throwable $e) {
            $this->handleError($bot, $e, 'createShift');
        }
    }

    /**
     * Determine shift number based on current time
     */
    private function determineShiftNumber(Carbon $now, \App\Services\SettingsService $settingsService, ?int $dealershipId): int
    {
        $shift1Start = Carbon::parse($now->format('Y-m-d') . ' ' . $settingsService->getShiftStartTime($dealershipId, 1));
        $shift2Start = Carbon::parse($now->format('Y-m-d') . ' ' . $settingsService->getShiftStartTime($dealershipId, 2));

        // If shift 2 starts later in the evening and we're past midnight, it's shift 2 from yesterday
        if ($shift2Start->greaterThan($shift1Start) && $now->lessThan($shift1Start)) {
            return 2;
        }

        // Otherwise, choose the closest shift
        return $now->diffInMinutes($shift1Start, false) < $now->diffInMinutes($shift2Start, false) ? 1 : 2;
    }

    /**
     * Send pending tasks to the employee
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
                $bot->sendMessage('✅ У вас нет активных задач.');
                return;
            }

            $bot->sendMessage("📋 У вас {$tasks->count()} активных задач:");

            foreach ($tasks as $task) {
                $this->sendTaskNotification($bot, $task, $user);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error sending tasks: ' . $e->getMessage());
        }
    }

    /**
     * Send task notification
     */
    private function sendTaskNotification(Nutgram $bot, \App\Models\Task $task, User $user): void
    {
        $message = "📌 *{$task->title}*\n\n";

        if ($task->description) {
            $message .= "{$task->description}\n\n";
        }

        if ($task->comment) {
            $message .= "💬 Комментарий: {$task->comment}\n\n";
        }

        if ($task->deadline) {
            $message .= "⏰ Дедлайн: " . $task->deadline->format('d.m.Y H:i') . "\n";
        }

        // Create response keyboard based on response_type
        $keyboard = match ($task->response_type) {
            'notification' => \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    text: '✅ OK',
                    callback_data: 'task_ok_' . $task->id
                )),
            'execution' => \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                ->addRow(
                    \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '✅ Выполнено',
                        callback_data: 'task_done_' . $task->id
                    ),
                    \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '⏭️ Перенести',
                        callback_data: 'task_postpone_' . $task->id
                    )
                ),
            default => null,
        };

        $bot->sendMessage($message, parse_mode: 'Markdown', reply_markup: $keyboard);
    }

    /**
     * Get default keyboard
     */
    protected function getDefaultKeyboard()
    {
        return static::employeeMenu();
    }
}
