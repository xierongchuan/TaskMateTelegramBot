<?php

declare(strict_types=1);

namespace App\Bot\Conversations\Guest;

use App\Bot\Abstracts\BaseConversation;
use App\Enums\Role;
use App\Models\User;
use App\Traits\MaterialDesign3Trait;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\Log;

/**
 * Conversation for user authentication via phone number.
 * Users must be pre-registered through API endpoints.
 *
 * Implements Material Design 3 principles:
 * - Clear visual hierarchy in messages
 * - Semantic iconography for actions
 * - Personalized greeting patterns
 */
class StartConversation extends BaseConversation
{
    use MaterialDesign3Trait;

    protected ?string $step = 'askContact';

    /**
     * Ask for user contact for authentication.
     * MD3: Form input pattern with clear instructions.
     */
    public function askContact(Nutgram $bot)
    {
        $message = implode("\n", [
            '🔐 *Вход в систему*',
            '',
            'Для входа поделитесь номером телефона.',
            '',
            'ℹ️ Аккаунт должен быть создан администратором.',
        ]);

        $bot->sendMessage(
            text: $message,
            reply_markup: static::contactRequestKeyboard(),
            parse_mode: 'markdown'
        );

        $this->next('getContact');
    }

    /**
     * Process contact and authenticate user.
     * MD3: Form validation with clear feedback.
     */
    public function getContact(Nutgram $bot)
    {
        try {
            $contact = $bot->message()->contact;

            if (!$contact?->phone_number) {
                $bot->sendMessage(
                    '❌ Номер не получен. Попробуйте ещё раз.',
                    reply_markup: static::contactRequestKeyboard()
                );
                $this->next('getContact');
                return;
            }

            $telegramUserId = $bot->user()?->id;
            if (!$telegramUserId) {
                Log::error('Не удалось получить Telegram ID для пользователя');
                $bot->sendMessage(
                    '❌ Ошибка авторизации. Попробуйте ещё раз.',
                    reply_markup: static::removeKeyboard()
                );
                $this->end();
                return;
            }

            // Normalize and validate phone number
            $phoneNumber = $contact->phone_number;
            $normalizedPhone = $this->normalizePhoneNumber($phoneNumber);

            if (!$this->isValidPhoneNumber($normalizedPhone)) {
                $bot->sendMessage(
                    '❌ Неверный формат номера. Используйте корректный номер.',
                    reply_markup: static::contactRequestKeyboard()
                );
                $this->next('getContact');
                return;
            }

            Log::info('Попытка входа в систему', [
                'telegram_id' => $telegramUserId,
                'phone' => $phoneNumber,
                'normalized_phone' => $normalizedPhone
            ]);

            // Check for existing Telegram ID binding first
            $existingTelegramUser = User::where('telegram_id', $telegramUserId)->first();
            if ($existingTelegramUser) {
                // User already authenticated with different phone
                if ($this->normalizePhoneNumber($existingTelegramUser->phone) !== $normalizedPhone) {
                    Log::warning('Попытка входа с другого номера', [
                        'telegram_id' => $telegramUserId,
                        'existing_phone' => $existingTelegramUser->phone,
                        'new_phone' => $phoneNumber
                    ]);

                    $message = implode("\n", [
                        '⚠️ *Аккаунт привязан*',
                        '',
                        'Этот Telegram привязан к номеру:',
                        $existingTelegramUser->phone,
                        '',
                        'Свяжитесь с администратором.',
                    ]);

                    $bot->sendMessage(
                        $message,
                        reply_markup: static::removeKeyboard(),
                        parse_mode: 'markdown'
                    );
                    $this->end();
                    return;
                }

                // Same user trying to login again
                $this->handleSuccessfulLogin($bot, $existingTelegramUser);
                return;
            }

            // Search user by phone number with multiple matching strategies
            $user = $this->findUserByPhone($normalizedPhone);

            if (!$user) {
                Log::info('Пользователь не найден в системе', [
                    'telegram_id' => $telegramUserId,
                    'phone' => $phoneNumber
                ]);

                $message = implode("\n", [
                    '❌ *Аккаунт не найден*',
                    '',
                    'Номер не зарегистрирован в системе.',
                    '',
                    '📞 Свяжитесь с администратором',
                    '• Предоставьте номер телефона',
                    '• Войдите после создания аккаунта',
                ]);

                $bot->sendMessage(
                    $message,
                    reply_markup: static::removeKeyboard(),
                    parse_mode: 'markdown'
                );
                $this->end();
                return;
            }

            // Check if phone is already bound to another Telegram account
            if ($user->telegram_id && $user->telegram_id !== $telegramUserId) {
                Log::warning('Номер телефона уже привязан к другому Telegram аккаунту', [
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'existing_telegram_id' => $user->telegram_id,
                    'new_telegram_id' => $telegramUserId
                ]);

                $message = implode("\n", [
                    '⚠️ *Номер уже привязан*',
                    '',
                    'Этот номер используется в другом Telegram.',
                    '',
                    'Свяжитесь с администратором.',
                ]);

                $bot->sendMessage(
                    $message,
                    reply_markup: static::removeKeyboard(),
                    parse_mode: 'markdown'
                );
                $this->end();
                return;
            }

            // Update user with Telegram ID
            $user->update(['telegram_id' => $telegramUserId]);

            $this->handleSuccessfulLogin($bot, $user);

        } catch (\Throwable $e) {
            Log::error('Ошибка при обработке входа', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->handleError($bot, $e, 'getContact');
        }
    }

    /**
     * Handle successful user login.
     * MD3: Success feedback with personalized greeting.
     */
    private function handleSuccessfulLogin(Nutgram $bot, User $user): void
    {
        Log::info('Пользователь успешно вошел в систему', [
            'user_id' => $user->id,
            'full_name' => $user->full_name,
            'role' => $user->role,
            'telegram_id' => $user->telegram_id
        ]);

        // Get appropriate keyboard based on role
        $keyboard = $this->getRoleKeyboard($user->role);
        $roleLabel = Role::tryFromString($user->role)?->label() ?? 'Сотрудник';
        $welcomeMessage = $this->generateWelcomeMessage($user, $roleLabel);

        $bot->sendMessage(
            $welcomeMessage,
            reply_markup: $keyboard,
            parse_mode: 'markdown'
        );

        $this->end();
    }

    /**
     * Generate personalized welcome message.
     * MD3: Expressive personalization with time-based greetings.
     */
    private function generateWelcomeMessage(User $user, string $roleLabel): string
    {
        $hour = (int) date('H');

        // MD3 time-based greeting with expressive icons
        $greeting = match (true) {
            $hour >= 5 && $hour < 12 => ['🌅', 'Доброе утро'],
            $hour >= 12 && $hour < 17 => ['☀️', 'Добрый день'],
            $hour >= 17 && $hour < 22 => ['🌆', 'Добрый вечер'],
            default => ['🌙', 'Доброй ночи'],
        };

        return implode("\n", [
            "{$greeting[0]} {$greeting[1]}, *{$user->full_name}*!",
            '',
            "✅ Вход выполнен · {$roleLabel}",
            '',
            'Выберите действие:',
        ]);
    }

    /**
     * Find user by phone number using multiple strategies.
     */
    private function findUserByPhone(string $normalizedPhone): ?User
    {
        // Strategy 1: Direct match with formatted numbers
        $formats = [
            '+' . $normalizedPhone,           // +79991234567
            $normalizedPhone,                 // 79991234567
            '8' . substr($normalizedPhone, 1), // 89991234567 (Russian format)
            substr($normalizedPhone, 1),     // 9991234567 (without country code)
        ];

        foreach ($formats as $format) {
            $user = User::where('phone', $format)->first();
            if ($user) return $user;
        }

        // Strategy 2: LIKE match for flexible matching
        $user = User::where('phone', 'like', '%' . $normalizedPhone . '%')->first();
        if ($user) {
            // Verify it's actually the same number (prevent false positives)
            $userNormalizedPhone = $this->normalizePhoneNumber($user->phone);
            if ($userNormalizedPhone === $normalizedPhone) {
                return $user;
            }
        }

        // Strategy 3: Handle country code variations with LIKE
        if (str_starts_with($normalizedPhone, '7') && strlen($normalizedPhone) === 11) {
            $last10Digits = substr($normalizedPhone, 1);

            // Try with +7 prefix
            $user = User::where('phone', 'like', '%+7' . $last10Digits . '%')->first();
            if ($user) return $user;

            // Try with 8 prefix
            $user = User::where('phone', 'like', '%8' . $last10Digits . '%')->first();
            if ($user) return $user;

            // Try with just 10 digits
            $user = User::where('phone', 'like', '%' . $last10Digits . '%')->first();
            if ($user) {
                $userNormalizedPhone = $this->normalizePhoneNumber($user->phone);
                if ($userNormalizedPhone === $normalizedPhone) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * Get keyboard based on user role.
     */
    private function getRoleKeyboard(string $role)
    {
        return match ($role) {
            Role::EMPLOYEE->value => static::employeeMenu(),
            Role::MANAGER->value => static::managerMenu(),
            Role::OBSERVER->value => static::observerMenu(),
            Role::OWNER->value => static::ownerMenu(),
            default => static::employeeMenu()
        };
    }

    /**
     * Normalize phone number for comparison and validation.
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $normalized = preg_replace('/\D+/', '', $phone);

        // Handle Russian number format conversions
        if (strlen($normalized) === 11) {
            if (str_starts_with($normalized, '8')) {
                // Convert 8xxx to 7xxx (Russian format)
                $normalized = '7' . substr($normalized, 1);
            }
        } elseif (strlen($normalized) === 10) {
            // Assume Russian number if 10 digits
            $normalized = '7' . $normalized;
        }

        return $normalized;
    }

    /**
     * Validate normalized phone number.
     */
    private function isValidPhoneNumber(string $phone): bool
    {
        // Basic validation: should be 10-15 digits
        $length = strlen($phone);
        return $length >= 10 && $length <= 15;
    }
}
