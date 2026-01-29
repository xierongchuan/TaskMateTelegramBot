"""Inline-клавиатуры для бота."""

from __future__ import annotations

from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup


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


def notification_task_actions(task_id: int, response_type: str) -> InlineKeyboardMarkup | None:
    """Кнопки для уведомления о новой задаче."""
    return task_actions(task_id, response_type, "pending")
