"""Клавиатуры для бота: inline и reply."""

from __future__ import annotations

from aiogram.types import (
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    KeyboardButton,
    ReplyKeyboardMarkup,
    ReplyKeyboardRemove,
)

# --- Тексты кнопок меню ---

BTN_MY_TASKS = "📋 Мои задачи"
BTN_TASKS = "📋 Задачи"
BTN_MY_SHIFT = "🕐 Моя смена"
BTN_SHIFTS = "🕐 Смены"
BTN_PENDING_REVIEW = "✅ На проверку"
BTN_OVERDUE = "🔴 Просрочены"
BTN_DASHBOARD = "📊 Дашборд"
BTN_LOGOUT = "🚪 Выход"


def main_menu(role: str) -> ReplyKeyboardMarkup:
    """Главное меню с кнопками по роли."""
    if role == "employee":
        rows = [
            [KeyboardButton(text=BTN_MY_TASKS), KeyboardButton(text=BTN_MY_SHIFT)],
            [KeyboardButton(text=BTN_DASHBOARD), KeyboardButton(text=BTN_LOGOUT)],
        ]
    elif role == "observer":
        rows = [
            [KeyboardButton(text=BTN_TASKS), KeyboardButton(text=BTN_SHIFTS)],
            [KeyboardButton(text=BTN_DASHBOARD), KeyboardButton(text=BTN_LOGOUT)],
        ]
    else:  # manager, owner
        rows = [
            [KeyboardButton(text=BTN_TASKS), KeyboardButton(text=BTN_PENDING_REVIEW)],
            [KeyboardButton(text=BTN_OVERDUE), KeyboardButton(text=BTN_SHIFTS)],
            [KeyboardButton(text=BTN_DASHBOARD), KeyboardButton(text=BTN_LOGOUT)],
        ]
    return ReplyKeyboardMarkup(keyboard=rows, resize_keyboard=True)


def remove_menu() -> ReplyKeyboardRemove:
    """Убрать клавиатуру."""
    return ReplyKeyboardRemove()


def task_actions(task_id: int, response_type: str, status: str) -> InlineKeyboardMarkup | None:
    """Кнопки действий для задачи в зависимости от типа и статуса."""
    buttons: list[list[InlineKeyboardButton]] = []

    if status in ("completed", "completed_late"):
        return None

    if response_type == "notification" and status == "pending":
        buttons.append([
            InlineKeyboardButton(
                text="👁 Ознакомлен",
                callback_data=f"ack:{task_id}",
            )
        ])

    elif response_type == "completion" and status in ("pending", "acknowledged"):
        buttons.append([
            InlineKeyboardButton(
                text="✅ Выполнено",
                callback_data=f"complete:{task_id}",
            ),
        ])

    elif response_type == "completion_with_proof":
        if status in ("pending", "acknowledged", "rejected"):
            buttons.append([
                InlineKeyboardButton(
                    text="📎 Загрузить доказательства",
                    callback_data=f"proof_start:{task_id}",
                ),
            ])

    if not buttons:
        return None
    return InlineKeyboardMarkup(inline_keyboard=buttons)


def proof_actions(task_id: int) -> InlineKeyboardMarkup:
    """Кнопки при загрузке доказательств."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="📤 Отправить на проверку",
                    callback_data=f"proof_submit:{task_id}",
                ),
            ],
            [
                InlineKeyboardButton(
                    text="❌ Отмена",
                    callback_data=f"proof_cancel:{task_id}",
                ),
            ],
        ]
    )


def task_list_item(task_id: int) -> InlineKeyboardMarkup:
    """Кнопка «Подробнее» для элемента списка."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="📖 Подробнее",
                    callback_data=f"task_detail:{task_id}",
                ),
            ]
        ]
    )


def review_actions(response_id: int) -> InlineKeyboardMarkup:
    """Кнопки одобрения/отклонения для одиночной задачи на проверке."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="✅ Одобрить",
                    callback_data=f"review_approve:{response_id}",
                ),
                InlineKeyboardButton(
                    text="❌ Отклонить",
                    callback_data=f"review_reject:{response_id}",
                ),
            ]
        ]
    )


def review_group_actions(task_id: int) -> InlineKeyboardMarkup:
    """Кнопки для групповой задачи на проверке."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="✅ Одобрить всем",
                    callback_data=f"review_approve_all:{task_id}",
                ),
                InlineKeyboardButton(
                    text="❌ Отклонить всем",
                    callback_data=f"review_reject_all:{task_id}",
                ),
            ],
            [
                InlineKeyboardButton(
                    text="📋 Индивидуально",
                    callback_data=f"review_individual:{task_id}",
                ),
            ],
        ]
    )


def notification_task_actions(task_id: int, response_type: str) -> InlineKeyboardMarkup | None:
    """Кнопки для уведомления о новой задаче."""
    return task_actions(task_id, response_type, "pending")
