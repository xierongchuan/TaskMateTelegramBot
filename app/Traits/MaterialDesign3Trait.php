<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Material Design 3 (MD3) Design System for Telegram Bot UI.
 *
 * This trait provides consistent styling patterns following Google's Material Design 3 guidelines,
 * adapted for Telegram Bot messaging interface.
 *
 * Key MD3 Principles Applied:
 * - Clear visual hierarchy through typography and spacing
 * - Semantic color usage (primary actions, errors, warnings, success)
 * - Consistent iconography with expressive emojis
 * - Accessibility-first approach with clear contrast
 * - Motion/feedback through clear state indicators
 *
 * @see https://m3.material.io/
 */
trait MaterialDesign3Trait
{
    // ═══════════════════════════════════════════════════════════════════
    // ICON SYSTEM (MD3 Expressive Icons)
    // Following MD3's expressive design with semantic emoji usage
    // ═══════════════════════════════════════════════════════════════════

    // Primary Actions (Filled icons - high emphasis)
    protected static string $iconSuccess = '✅';
    protected static string $iconError = '❌';
    protected static string $iconWarning = '⚠️';
    protected static string $iconInfo = 'ℹ️';

    // Navigation & Interaction
    protected static string $iconBack = '◀️';
    protected static string $iconForward = '▶️';
    protected static string $iconExpand = '🔽';
    protected static string $iconCollapse = '🔼';
    protected static string $iconMenu = '☰';
    protected static string $iconDismiss = '✖️';

    // Status Indicators (MD3 semantic colors)
    protected static string $iconOnline = '🟢';
    protected static string $iconOffline = '⚫';
    protected static string $iconBusy = '🔴';
    protected static string $iconAway = '🟡';
    protected static string $iconPending = '🔵';

    // Tasks & Work (Outlined style - medium emphasis)
    protected static string $iconTask = '📋';
    protected static string $iconTaskDone = '☑️';
    protected static string $iconTaskPending = '⏳';
    protected static string $iconDeadline = '⏰';
    protected static string $iconUrgent = '🚨';
    protected static string $iconOverdue = '⏱️';

    // Business Objects
    protected static string $iconDealership = '🏢';
    protected static string $iconEmployee = '👤';
    protected static string $iconTeam = '👥';
    protected static string $iconShift = '📊';
    protected static string $iconCalendar = '📅';
    protected static string $iconLocation = '📍';

    // Communication
    protected static string $iconComment = '💬';
    protected static string $iconNotification = '🔔';
    protected static string $iconPhone = '📱';
    protected static string $iconEmail = '📧';

    // Actions
    protected static string $iconOpen = '🔓';
    protected static string $iconClose = '🔒';
    protected static string $iconCamera = '📷';
    protected static string $iconPhoto = '🖼️';
    protected static string $iconEdit = '✏️';
    protected static string $iconDelete = '🗑️';
    protected static string $iconSave = '💾';
    protected static string $iconShare = '📤';

    // Progress & States
    protected static string $iconProgress = '📈';
    protected static string $iconComplete = '🎯';
    protected static string $iconSkip = '⏭️';
    protected static string $iconRefresh = '🔄';
    protected static string $iconLoading = '⏳';

    // Time-based Greetings
    protected static string $iconMorning = '🌅';
    protected static string $iconDay = '☀️';
    protected static string $iconEvening = '🌆';
    protected static string $iconNight = '🌙';

    // Tags & Categories
    protected static string $iconTag = '🏷️';
    protected static string $iconCategory = '📂';
    protected static string $iconStar = '⭐';
    protected static string $iconPin = '📌';

    // Money & Values
    protected static string $iconMoney = '💰';
    protected static string $iconPayment = '💳';

    // Hints & Help
    protected static string $iconTip = '💡';
    protected static string $iconHelp = '❓';
    protected static string $iconNote = '📝';

    // ═══════════════════════════════════════════════════════════════════
    // TYPOGRAPHY PATTERNS (MD3 Type Scale)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Format a display-level headline (largest, for major announcements).
     * MD3: Display typography for hero messages.
     */
    protected static function md3Display(string $text): string
    {
        return "✦ *{$text}* ✦";
    }

    /**
     * Format a headline (section headers).
     * MD3: Headline typography for section titles.
     */
    protected static function md3Headline(string $text): string
    {
        return "*{$text}*";
    }

    /**
     * Format a title (card/item titles).
     * MD3: Title typography for list items.
     */
    protected static function md3Title(string $text): string
    {
        return "*{$text}*";
    }

    /**
     * Format body text (main content).
     * MD3: Body typography for readable content.
     */
    protected static function md3Body(string $text): string
    {
        return $text;
    }

    /**
     * Format label text (small, supporting).
     * MD3: Label typography for metadata.
     */
    protected static function md3Label(string $text): string
    {
        return "_{$text}_";
    }

    // ═══════════════════════════════════════════════════════════════════
    // SPACING & LAYOUT (MD3 Spacing System)
    // ═══════════════════════════════════════════════════════════════════

    protected static string $dividerLight = "─────────────────────";
    protected static string $dividerMedium = "━━━━━━━━━━━━━━━━━";
    protected static string $lineBreak = "\n";
    protected static string $sectionBreak = "\n\n";
    protected static string $paragraphBreak = "\n\n";

    /**
     * Create a visual section divider.
     * MD3: Surface elevation through visual separation.
     */
    protected static function md3Divider(): string
    {
        return "\n" . static::$dividerLight . "\n";
    }

    /**
     * Create a section header with divider.
     * MD3: Clear content hierarchy.
     */
    protected static function md3Section(string $title, string $icon = ''): string
    {
        $prefix = $icon ? "{$icon} " : '';
        return "\n{$prefix}*{$title}*\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    // MESSAGE CARD PATTERNS (MD3 Cards)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Format a standard notification card.
     * MD3: Filled card pattern with clear hierarchy.
     *
     * @param string $icon Leading icon
     * @param string $title Card title
     * @param string|null $subtitle Optional subtitle
     * @param string|null $body Optional body content
     * @param array<string, string> $metadata Key-value pairs for metadata
     */
    protected static function md3Card(
        string $icon,
        string $title,
        ?string $subtitle = null,
        ?string $body = null,
        array $metadata = []
    ): string {
        $lines = [];

        // Header with icon and title
        $lines[] = "{$icon} *{$title}*";

        // Optional subtitle
        if ($subtitle) {
            $lines[] = $subtitle;
        }

        // Body content with proper spacing
        if ($body) {
            $lines[] = '';
            $lines[] = $body;
        }

        // Metadata section
        if (!empty($metadata)) {
            $lines[] = '';
            foreach ($metadata as $key => $value) {
                $lines[] = "{$key}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format an alert/notification card with emphasis.
     * MD3: Elevated card pattern for important messages.
     */
    protected static function md3AlertCard(
        string $icon,
        string $title,
        string $message,
        string $level = 'info'
    ): string {
        $lines = [];

        // Alert header with emphasis
        $lines[] = "{$icon} *{$title}*";
        $lines[] = '';
        $lines[] = $message;

        return implode("\n", $lines);
    }

    /**
     * Format a list item.
     * MD3: List item pattern with leading icon.
     */
    protected static function md3ListItem(string $text, ?string $icon = null, ?string $trailing = null): string
    {
        $prefix = $icon ? "{$icon} " : '• ';
        $suffix = $trailing ? " {$trailing}" : '';
        return "{$prefix}{$text}{$suffix}";
    }

    // ═══════════════════════════════════════════════════════════════════
    // STATUS & FEEDBACK PATTERNS (MD3 States)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Format a success message.
     * MD3: Success state with primary color emphasis.
     */
    protected static function md3Success(string $message): string
    {
        return static::$iconSuccess . ' ' . $message;
    }

    /**
     * Format an error message.
     * MD3: Error state with error color emphasis.
     */
    protected static function md3Error(string $message): string
    {
        return static::$iconError . ' ' . $message;
    }

    /**
     * Format a warning message.
     * MD3: Warning state with caution color emphasis.
     */
    protected static function md3Warning(string $message): string
    {
        return static::$iconWarning . ' ' . $message;
    }

    /**
     * Format an info message.
     * MD3: Info state with neutral emphasis.
     */
    protected static function md3Info(string $message): string
    {
        return static::$iconInfo . ' ' . $message;
    }

    /**
     * Format a hint/tip message.
     * MD3: Supporting text pattern.
     */
    protected static function md3Tip(string $message): string
    {
        return static::$iconTip . ' ' . $message;
    }

    // ═══════════════════════════════════════════════════════════════════
    // TIME-BASED GREETINGS (MD3 Personalization)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get appropriate greeting based on time of day.
     * MD3: Personalized, expressive communication.
     */
    protected static function md3Greeting(): array
    {
        $hour = (int) date('H');

        return match (true) {
            $hour >= 5 && $hour < 12 => [
                'icon' => static::$iconMorning,
                'text' => 'Доброе утро'
            ],
            $hour >= 12 && $hour < 17 => [
                'icon' => static::$iconDay,
                'text' => 'Добрый день'
            ],
            $hour >= 17 && $hour < 22 => [
                'icon' => static::$iconEvening,
                'text' => 'Добрый вечер'
            ],
            default => [
                'icon' => static::$iconNight,
                'text' => 'Доброй ночи'
            ],
        };
    }

    /**
     * Format a personalized greeting message.
     */
    protected static function md3GreetingMessage(string $name, ?string $role = null): string
    {
        $greeting = static::md3Greeting();
        $roleText = $role ? ", {$role}" : '';

        return "{$greeting['icon']} {$greeting['text']}{$roleText} *{$name}*!";
    }

    // ═══════════════════════════════════════════════════════════════════
    // BUTTON LABEL PATTERNS (MD3 Buttons)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Format a primary action button label.
     * MD3: Filled button - high emphasis.
     */
    protected static function md3ButtonPrimary(string $icon, string $label): string
    {
        return "{$icon} {$label}";
    }

    /**
     * Format a secondary action button label.
     * MD3: Outlined/tonal button - medium emphasis.
     */
    protected static function md3ButtonSecondary(string $label): string
    {
        return $label;
    }

    /**
     * Format a text button label.
     * MD3: Text button - low emphasis.
     */
    protected static function md3ButtonText(string $label): string
    {
        return $label;
    }

    // ═══════════════════════════════════════════════════════════════════
    // SEMANTIC ACTION LABELS (MD3 Action Patterns)
    // ═══════════════════════════════════════════════════════════════════

    // Primary Actions (Filled buttons)
    protected static string $labelConfirm = '✓ Подтвердить';
    protected static string $labelComplete = '✓ Выполнено';
    protected static string $labelAccept = '✓ Принять';
    protected static string $labelSend = '↗ Отправить';

    // Secondary Actions (Outlined buttons)
    protected static string $labelCancel = 'Отмена';
    protected static string $labelDecline = 'Отклонить';
    protected static string $labelSkip = 'Пропустить';
    protected static string $labelBack = '← Назад';

    // Shift Actions
    protected static string $labelOpenShift = '🔓 Открыть смену';
    protected static string $labelCloseShift = '🔒 Закрыть смену';

    // Navigation Actions
    protected static string $labelViewShifts = '📊 Смены';
    protected static string $labelViewTasks = '📋 Задачи';
    protected static string $labelViewDealerships = '🏢 Салоны';
    protected static string $labelViewEmployees = '👥 Сотрудники';
    protected static string $labelViewStats = '📊 Статистика';

    // Task Actions
    protected static string $labelTaskOk = '✓ Принято';
    protected static string $labelTaskDone = '✓ Выполнено';
    protected static string $labelAddComment = '💬 Добавить комментарий';

    // ═══════════════════════════════════════════════════════════════════
    // DATA FORMATTING (MD3 Presentation)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Format a date in a human-readable way.
     * MD3: Clear, localized date presentation.
     */
    protected static function md3FormatDate(string $date, string $format = 'd.m.Y'): string
    {
        return date($format, strtotime($date));
    }

    /**
     * Format a time value.
     * MD3: Clear time presentation.
     */
    protected static function md3FormatTime(string $time, string $format = 'H:i'): string
    {
        return date($format, strtotime($time));
    }

    /**
     * Format a datetime with both date and time.
     */
    protected static function md3FormatDateTime(string $datetime): string
    {
        return date('H:i d.m.Y', strtotime($datetime));
    }

    /**
     * Format duration in hours and minutes.
     */
    protected static function md3FormatDuration(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}ч {$mins}м";
        } elseif ($hours > 0) {
            return "{$hours}ч";
        } else {
            return "{$mins}м";
        }
    }

    /**
     * Format a relative time (e.g., "через 30 минут", "2 часа назад").
     */
    protected static function md3FormatRelativeTime(int $minutes, bool $future = true): string
    {
        $hours = floor(abs($minutes) / 60);
        $mins = abs($minutes) % 60;

        $timeStr = '';
        if ($hours > 0) {
            $timeStr = $hours . ' ' . static::pluralizeRu($hours, 'час', 'часа', 'часов');
            if ($mins > 0) {
                $timeStr .= ' ' . $mins . ' ' . static::pluralizeRu($mins, 'минута', 'минуты', 'минут');
            }
        } else {
            $timeStr = $mins . ' ' . static::pluralizeRu($mins, 'минута', 'минуты', 'минут');
        }

        return $future ? "через {$timeStr}" : "{$timeStr} назад";
    }

    /**
     * Russian pluralization helper.
     */
    protected static function pluralizeRu(int $number, string $one, string $few, string $many): string
    {
        $mod10 = $number % 10;
        $mod100 = $number % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }

    // ═══════════════════════════════════════════════════════════════════
    // STATUS BADGES (MD3 Chips/Badges)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Format a status badge.
     * MD3: Filter chip / status indicator pattern.
     */
    protected static function md3StatusBadge(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'open', 'online' => static::$iconOnline . ' Активно',
            'completed', 'done', 'closed' => static::$iconSuccess . ' Завершено',
            'pending', 'waiting' => static::$iconPending . ' В ожидании',
            'overdue', 'late' => static::$iconBusy . ' Просрочено',
            'cancelled', 'rejected' => static::$iconError . ' Отменено',
            default => $status,
        };
    }

    /**
     * Format a priority indicator.
     * MD3: Visual emphasis based on priority level.
     */
    protected static function md3PriorityBadge(string $priority): string
    {
        return match (strtolower($priority)) {
            'high', 'urgent', 'critical' => static::$iconUrgent . ' Срочно',
            'medium', 'normal' => static::$iconPending . ' Обычный',
            'low' => static::$iconInfo . ' Низкий',
            default => $priority,
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // COMPLEX MESSAGE BUILDERS (MD3 Templates)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Build a task notification message following MD3 patterns.
     */
    protected static function md3TaskNotification(
        string $title,
        ?string $description = null,
        ?string $comment = null,
        ?string $deadline = null,
        ?array $tags = null
    ): string {
        $lines = [];

        // Header
        $lines[] = static::$iconPin . ' *' . $title . '*';

        // Description
        if ($description) {
            $lines[] = '';
            $lines[] = $description;
        }

        // Comment
        if ($comment) {
            $lines[] = '';
            $lines[] = static::$iconComment . ' ' . $comment;
        }

        // Metadata section
        $metadataLines = [];

        if ($deadline) {
            $metadataLines[] = static::$iconDeadline . ' Дедлайн: ' . $deadline;
        }

        if ($tags && !empty($tags)) {
            $metadataLines[] = static::$iconTag . ' ' . implode(', ', $tags);
        }

        if (!empty($metadataLines)) {
            $lines[] = '';
            $lines = array_merge($lines, $metadataLines);
        }

        return implode("\n", $lines);
    }

    /**
     * Build a shift status message following MD3 patterns.
     */
    protected static function md3ShiftMessage(
        string $action,
        string $time,
        ?string $duration = null,
        ?string $status = null,
        ?int $lateMinutes = null,
        ?string $replacingUser = null,
        ?string $replacementReason = null
    ): string {
        $lines = [];

        // Main action result
        $icon = $action === 'open' ? static::$iconOpen : static::$iconClose;
        $actionText = $action === 'open' ? 'Смена открыта' : 'Смена закрыта';
        $lines[] = static::$iconSuccess . ' *' . $actionText . '*';
        $lines[] = static::$iconDeadline . ' ' . $time;

        // Duration (for closed shifts)
        if ($duration) {
            $lines[] = '';
            $lines[] = '⏱️ Продолжительность: ' . $duration;
        }

        // Late status
        if ($lateMinutes && $lateMinutes > 0) {
            $lines[] = '';
            $lines[] = static::$iconWarning . ' Опоздание: ' . $lateMinutes . ' ' .
                       static::pluralizeRu($lateMinutes, 'минута', 'минуты', 'минут');
        }

        // Replacement info
        if ($replacingUser) {
            $lines[] = '';
            $lines[] = static::$iconNote . ' Замена: ' . $replacingUser;
            if ($replacementReason) {
                $lines[] = static::$iconComment . ' Причина: ' . $replacementReason;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build a deadline reminder message following MD3 patterns.
     */
    protected static function md3DeadlineReminder(
        string $title,
        string $deadlineTime,
        int $minutesUntil,
        ?string $description = null
    ): string {
        $lines = [];

        // Urgent header
        $lines[] = static::$iconDeadline . ' *НАПОМИНАНИЕ О ДЕДЛАЙНЕ*';
        $lines[] = '';
        $lines[] = static::$iconPin . ' *' . $title . '*';

        if ($description) {
            $lines[] = '';
            $lines[] = $description;
        }

        $lines[] = '';
        $lines[] = static::$iconUrgent . ' Дедлайн ' . static::md3FormatRelativeTime($minutesUntil, true) . '!';
        $lines[] = static::$iconDeadline . ' Время: ' . $deadlineTime;

        return implode("\n", $lines);
    }

    /**
     * Build an overdue notification message following MD3 patterns.
     */
    protected static function md3OverdueNotification(
        string $title,
        string $deadlineTime,
        string $overdueTime,
        ?string $description = null
    ): string {
        $lines = [];

        // Urgent header
        $lines[] = static::$iconWarning . ' *СРОК ВЫПОЛНЕНИЯ ИСТЁК*';
        $lines[] = '';
        $lines[] = static::$iconPin . ' *' . $title . '*';

        if ($description) {
            $lines[] = '';
            $lines[] = $description;
        }

        $lines[] = '';
        $lines[] = static::$iconUrgent . ' Дедлайн был: ' . $deadlineTime;
        $lines[] = static::$iconOverdue . ' Просрочено на: ' . $overdueTime;

        return implode("\n", $lines);
    }
}
