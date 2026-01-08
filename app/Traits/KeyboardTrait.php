<?php

declare(strict_types=1);

namespace App\Traits;

use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Keyboard layouts following Material Design 3 principles.
 *
 * MD3 Button Guidelines Applied:
 * - Primary actions use filled style with leading icons (high emphasis)
 * - Secondary actions use outlined/tonal style (medium emphasis)
 * - Tertiary actions use text style (low emphasis)
 * - Clear visual hierarchy between action types
 * - Consistent iconography and spacing
 *
 * @see https://m3.material.io/components/buttons
 */
trait KeyboardTrait
{
    // ═══════════════════════════════════════════════════════════════════
    // ROLE-BASED NAVIGATION MENUS (MD3 Navigation Rail pattern)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Employee navigation menu.
     * MD3: Bottom navigation pattern with primary actions.
     */
    public static function employeeMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: false)
            ->addRow(
                KeyboardButton::make('🔓 Открыть смену'),
                KeyboardButton::make('🔒 Закрыть смену')
            );
    }

    /**
     * Manager navigation menu.
     * MD3: Navigation with overview actions.
     */
    public static function managerMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true)
            ->addRow(
                KeyboardButton::make('📊 Смены'),
                KeyboardButton::make('📋 Задачи')
            );
    }

    /**
     * Observer navigation menu.
     * MD3: Read-only navigation pattern.
     */
    public static function observerMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true)
            ->addRow(
                KeyboardButton::make('📊 Просмотр смен'),
                KeyboardButton::make('📋 Просмотр задач')
            );
    }

    /**
     * Owner navigation menu.
     * MD3: Full navigation with all sections.
     */
    public static function ownerMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true)
            ->addRow(
                KeyboardButton::make('🏢 Салоны'),
                KeyboardButton::make('👥 Сотрудники')
            )
            ->addRow(
                KeyboardButton::make('📊 Смены'),
                KeyboardButton::make('📋 Задачи')
            );
    }

    // ═══════════════════════════════════════════════════════════════════
    // CONTACT & AUTHENTICATION (MD3 Form Inputs)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Contact request keyboard for authentication.
     * MD3: Filled button style for primary CTA.
     */
    public static function contactRequestKeyboard(string $label = '📱 Поделиться номером'): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make($label, request_contact: true));
    }

    // ═══════════════════════════════════════════════════════════════════
    // CONFIRMATION DIALOGS (MD3 Dialog patterns)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Single confirm action button.
     * MD3: Filled button for single primary action.
     */
    public static function inlineConfirmIssued(
        string $confirmData = 'confirm',
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✓ Выдано', callback_data: $confirmData),
            );
    }

    /**
     * Amount confirmation with options.
     * MD3: Primary action first, secondary action second.
     */
    public static function inlineConfirmIssuedWithAmount(
        string $confirmFullData = 'confirm_full',
        string $confirmDifferentData = 'confirm_different_amount'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✓ Полная сумма', callback_data: $confirmFullData),
            )
            ->addRow(
                InlineKeyboardButton::make(text: '💰 Другая сумма', callback_data: $confirmDifferentData),
            );
    }

    /**
     * Confirm/Decline dialog.
     * MD3: Alert dialog pattern - confirm left, cancel right.
     */
    public static function inlineConfirmDecline(
        string $confirmData = 'confirm',
        string $declineData = 'decline'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✓ Подтвердить', callback_data: $confirmData),
                InlineKeyboardButton::make(text: 'Отмена', callback_data: $declineData),
            );
    }

    /**
     * Confirm/Comment/Decline dialog with three actions.
     * MD3: Multi-action dialog pattern.
     */
    public static function inlineConfirmCommentDecline(
        string $confirmData = 'confirm',
        string $confirmWithCommentData = 'confirm_with_comment',
        string $declineData = 'decline'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '✓ Подтвердить', callback_data: $confirmData),
                InlineKeyboardButton::make(text: 'Отмена', callback_data: $declineData),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    text: '💬 С комментарием',
                    callback_data: $confirmWithCommentData
                ),
            );
    }

    // ═══════════════════════════════════════════════════════════════════
    // TASK RESPONSE BUTTONS (MD3 Action Chips)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Task acknowledgment button (OK response type).
     * MD3: Filled tonal button for acknowledge action.
     */
    public static function taskAcknowledgeButton(int $taskId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    text: '✓ Принято',
                    callback_data: 'task_ok_' . $taskId
                )
            );
    }

    /**
     * Task completion button (complete response type).
     * MD3: Filled button for primary completion action.
     */
    public static function taskCompleteButton(int $taskId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    text: '✓ Выполнено',
                    callback_data: 'task_done_' . $taskId
                )
            );
    }

    /**
     * Get task response keyboard based on response type.
     * MD3: Contextual action buttons.
     */
    public static function getTaskKeyboard(string $responseType, int $taskId): ?InlineKeyboardMarkup
    {
        return match ($responseType) {
            'acknowledge' => static::taskAcknowledgeButton($taskId),
            'complete' => static::taskCompleteButton($taskId),
            default => null,
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // PHOTO UPLOAD FLOW (MD3 Step-by-step dialogs)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Photo upload dialog with skip and cancel options.
     * MD3: Dialog with primary, secondary, and tertiary actions.
     */
    public static function photoUploadKeyboard(
        string $skipData = 'skip_photo',
        string $cancelData = 'cancel'
    ): InlineKeyboardMarkup {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(text: '⏭️ Пропустить', callback_data: $skipData),
            )
            ->addRow(
                InlineKeyboardButton::make(text: 'Отмена', callback_data: $cancelData),
            );
    }

    // ═══════════════════════════════════════════════════════════════════
    // UTILITY KEYBOARDS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Remove reply keyboard.
     */
    public static function removeKeyboard(): ReplyKeyboardRemove
    {
        return ReplyKeyboardRemove::make(true, selective: false);
    }

    /**
     * Generate inline keyboard from array structure.
     * MD3: Dynamic keyboard generation following button guidelines.
     */
    public static function inlineFromArray(array $buttons): InlineKeyboardMarkup
    {
        $ik = InlineKeyboardMarkup::make();
        foreach ($buttons as $row) {
            $ikRow = [];
            foreach ($row as $btn) {
                $ikRow[] = InlineKeyboardButton::make(text: $btn['text'], callback_data: $btn['data']);
            }
            $ik->row($ikRow);
        }
        return $ik;
    }

    /**
     * Yes/No quick response keyboard.
     * MD3: Binary choice dialog pattern.
     */
    public static function yesNoKeyboard(string $yes = '✓ Да', string $no = 'Нет'): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(
                KeyboardButton::make($yes),
                KeyboardButton::make($no)
            );
    }

    /**
     * Single cancel button.
     * MD3: Text button for dismissive action.
     */
    public static function cancelKeyboard(string $text = 'Отмена', string $data = 'cancel'): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(text: $text, callback_data: $data));
    }

    // ═══════════════════════════════════════════════════════════════════
    // EMPLOYEE SELECTION (MD3 Selection List)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Generate employee selection keyboard.
     * MD3: List selection pattern with consistent styling.
     */
    public static function employeeSelectionKeyboard(array $employees): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($employees as $employee) {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: '👤 ' . $employee['name'],
                    callback_data: 'employee_' . $employee['id']
                )
            );
        }

        return $keyboard;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PAGINATION (MD3 Navigation Pattern)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Generate pagination keyboard.
     * MD3: Pagination with clear navigation controls.
     */
    public static function paginationKeyboard(
        int $currentPage,
        int $totalPages,
        string $prefix = 'page'
    ): ?InlineKeyboardMarkup {
        if ($totalPages <= 1) {
            return null;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $buttons = [];

        // Previous button
        if ($currentPage > 1) {
            $buttons[] = InlineKeyboardButton::make(
                text: '◀️',
                callback_data: "{$prefix}_" . ($currentPage - 1)
            );
        }

        // Page indicator
        $buttons[] = InlineKeyboardButton::make(
            text: "{$currentPage}/{$totalPages}",
            callback_data: 'noop'
        );

        // Next button
        if ($currentPage < $totalPages) {
            $buttons[] = InlineKeyboardButton::make(
                text: '▶️',
                callback_data: "{$prefix}_" . ($currentPage + 1)
            );
        }

        $keyboard->row($buttons);
        return $keyboard;
    }
}
