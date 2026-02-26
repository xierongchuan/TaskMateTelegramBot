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
                callback_data=f"complete_confirm:{task_id}",
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


def complete_confirmation(task_id: int) -> InlineKeyboardMarkup:
    """Кнопки подтверждения выполнения задачи."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="✅ Да, выполнено",
                    callback_data=f"complete:{task_id}",
                ),
                InlineKeyboardButton(
                    text="❌ Отмена",
                    callback_data=f"complete_cancel:{task_id}",
                ),
            ]
        ]
    )


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


# --- Смены: открытие/закрытие ---


def shift_actions_no_shift() -> InlineKeyboardMarkup:
    """Кнопка открытия смены (нет открытой смены)."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="📸 Открыть смену", callback_data="shift_open")]
        ]
    )


def shift_actions_open(shift_id: int) -> InlineKeyboardMarkup:
    """Кнопка закрытия смены (есть открытая смена)."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(
                text="🔒 Закрыть смену",
                callback_data=f"shift_close:{shift_id}",
            )]
        ]
    )


def dealership_selector(dealerships: list[dict]) -> InlineKeyboardMarkup:
    """Выбор автосалона при открытии смены."""
    buttons = [
        [InlineKeyboardButton(
            text=f"🏢 {d['name']}",
            callback_data=f"shift_dealer:{d['id']}",
        )]
        for d in dealerships
    ]
    buttons.append([InlineKeyboardButton(text="❌ Отмена", callback_data="shift_open_cancel")])
    return InlineKeyboardMarkup(inline_keyboard=buttons)


def shift_open_cancel() -> InlineKeyboardMarkup:
    """Кнопка отмены при ожидании фото открытия."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text="❌ Отмена", callback_data="shift_open_cancel")]
        ]
    )


def shift_close_options(shift_id: int) -> InlineKeyboardMarkup:
    """Кнопки при закрытии смены: без фото / отмена."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(
                text="📷 Без фото",
                callback_data=f"shift_close_nophoto:{shift_id}",
            )],
            [InlineKeyboardButton(text="❌ Отмена", callback_data="shift_close_cancel")],
        ]
    )


def reject_cancel_keyboard() -> InlineKeyboardMarkup:
    """Кнопка отмены при вводе причины отклонения."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="❌ Отмена",
                    callback_data="reject_cancel",
                ),
            ]
        ]
    )


# --- Делегирование ---


def delegation_user_selector(
    task_id: int, users: list[dict],
) -> InlineKeyboardMarkup:
    """Список сотрудников для делегирования (inline кнопки)."""
    buttons = [
        [InlineKeyboardButton(
            text=f"👤 {u.get('full_name', u.get('login', '—'))}",
            callback_data=f"dlg_user:{task_id}:{u['id']}",
        )]
        for u in users[:20]
    ]
    buttons.append([InlineKeyboardButton(
        text="❌ Отмена",
        callback_data=f"dlg_cancel_flow:{task_id}",
    )])
    return InlineKeyboardMarkup(inline_keyboard=buttons)


def delegation_reason_options(
    task_id: int, to_user_id: int,
) -> InlineKeyboardMarkup:
    """Пропустить причину / отмена."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="⏭ Пропустить",
                    callback_data=f"dlg_skip:{task_id}:{to_user_id}",
                ),
                InlineKeyboardButton(
                    text="❌ Отмена",
                    callback_data=f"dlg_cancel_flow:{task_id}",
                ),
            ]
        ]
    )


def delegation_incoming_actions(delegation_id: int) -> InlineKeyboardMarkup:
    """Принять / отклонить входящую делегацию."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="✅ Принять",
                    callback_data=f"dlg_accept:{delegation_id}",
                ),
                InlineKeyboardButton(
                    text="❌ Отклонить",
                    callback_data=f"dlg_reject:{delegation_id}",
                ),
            ]
        ]
    )


def delegation_cancel_button(delegation_id: int) -> InlineKeyboardMarkup:
    """Отменить исходящую делегацию."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(
                text="🚫 Отменить делегирование",
                callback_data=f"dlg_cancel:{delegation_id}",
            )]
        ]
    )


def delegation_reject_cancel() -> InlineKeyboardMarkup:
    """Отмена ввода причины отклонения делегирования."""
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(
                text="❌ Отмена",
                callback_data="dlg_reject_cancel",
            )]
        ]
    )
