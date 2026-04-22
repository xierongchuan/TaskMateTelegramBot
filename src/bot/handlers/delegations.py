"""Обработчики делегирования задач: создание, принятие, отклонение, отмена."""

from __future__ import annotations

import logging
from typing import Any

import httpx
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import CallbackQuery, Message

from ...api.client import TaskMateAPI
from ...storage.sessions import UserSession
from .. import keyboards, messages

logger = logging.getLogger(__name__)
router = Router()


class DelegationReason(StatesGroup):
    """FSM: ожидание опциональной причины делегирования."""

    waiting = State()


class DelegationRejectReason(StatesGroup):
    """FSM: ожидание обязательной причины отклонения делегирования."""

    waiting = State()


# --- Команда /delegations ---


@router.message(Command("delegations"))
async def cmd_delegations(message: Message, session: UserSession, **kwargs) -> None:
    """Список активных делегирований пользователя."""
    reply_kb = kwargs.get("reply_keyboard")
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_delegations({"status": "pending", "per_page": 20})
    except Exception:
        logger.exception("Ошибка при получении делегирований")
        await message.answer(messages.error_generic(), reply_markup=reply_kb)
        return

    delegations = result.get("data", [])
    text = messages.delegation_list(delegations)
    await message.answer(text, reply_markup=reply_kb)

    # Показать кнопки действий для каждой делегации
    for d in delegations:
        dlg_id = d.get("id")
        if not dlg_id:
            continue
        to_user_id = d.get("to_user", {}).get("id")
        from_user_id = d.get("from_user", {}).get("id")

        task = d.get("task", {})
        task_id = task.get("id", "?")
        task_title = task.get("title", "—")
        task_desc = task.get("description", "")
        deadline = task.get("deadline")
        priority = task.get("priority", "medium")
        from_name = d.get("from_user", {}).get("full_name", "—")
        to_name = d.get("to_user", {}).get("full_name", "—")
        reason = d.get("reason", "")

        priority_icon = {"low": "🟢", "medium": "🟡", "high": "🔴"}.get(priority, "⚪")

        task_info = f"{priority_icon} <b>Задача #{task_id}</b>: {task_title}"

        if task_desc:
            desc_preview = task_desc[:80] + "..." if len(task_desc) > 80 else task_desc
            task_info += f"\n   <i>{desc_preview}</i>"

        if deadline:
            from datetime import datetime
            try:
                dt = datetime.fromisoformat(deadline.replace("Z", "+00:00"))
                task_info += f"\n   📅 Дедлайн: {dt.strftime('%d.%m.%Y %H:%M')}"
            except Exception:
                pass

        if reason:
            task_info += f"\n   💬 Причина: {reason}"

        if to_user_id == session.user_id:
            kb = keyboards.delegation_incoming_actions(dlg_id)
            await message.answer(
                f"📥 Делегирование #{dlg_id} от {from_name}\n\n{task_info}",
                reply_markup=kb,
            )
        elif from_user_id == session.user_id:
            kb = keyboards.delegation_cancel_button(dlg_id)
            await message.answer(
                f"📤 Делегирование #{dlg_id} → {to_name}\n\n{task_info}",
                reply_markup=kb,
            )


# --- Поток создания делегации ---


@router.callback_query(F.data.startswith("dlg_start:"))
async def cb_delegation_start(
    callback: CallbackQuery,
    session: UserSession,
) -> None:
    """Начало делегирования: показать список сотрудников."""
    task_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)

    try:
        task_data = await api.get_task(task_id)
    except Exception:
        await callback.answer("Ошибка загрузки задачи", show_alert=True)
        return

    # Определить dealership_id задачи
    dealership_id = task_data.get("dealership_id")
    if not dealership_id:
        dealership = task_data.get("dealership", {})
        dealership_id = dealership.get("id") if dealership else None

    # Получить список сотрудников того же автосалона
    params: dict[str, Any] = {"per_page": 50, "role": "employee"}
    if dealership_id:
        params["dealership_id"] = dealership_id

    try:
        users_result = await api.get_users(params)
    except Exception:
        await callback.answer("Ошибка загрузки сотрудников", show_alert=True)
        return

    users = users_result.get("data", [])

    # Исключить текущего пользователя и уже назначенных
    assigned_ids = set()
    for assignment in task_data.get("assignments", []):
        uid = assignment.get("user_id") or assignment.get("user", {}).get("id")
        if uid:
            assigned_ids.add(uid)

    eligible = [
        u for u in users if u["id"] != session.user_id and u["id"] not in assigned_ids
    ]

    if not eligible:
        await callback.message.answer(messages.no_eligible_users())
        await callback.answer()
        return

    kb = keyboards.delegation_user_selector(task_id, eligible)
    await callback.message.answer(
        messages.delegation_select_user_prompt(task_id),
        reply_markup=kb,
    )
    await callback.answer()


@router.callback_query(F.data.startswith("dlg_user:"))
async def cb_delegation_user_selected(
    callback: CallbackQuery,
    state: FSMContext,
) -> None:
    """Пользователь выбран — запросить причину."""
    parts = callback.data.split(":")
    task_id = int(parts[1])
    to_user_id = int(parts[2])

    # Извлечь имя из кнопки
    to_user_name = "сотрудник"
    if callback.message and callback.message.reply_markup:
        for row in callback.message.reply_markup.inline_keyboard:
            for btn in row:
                if btn.callback_data == callback.data:
                    to_user_name = btn.text.replace("👤 ", "")
                    break

    await state.set_state(DelegationReason.waiting)
    await state.update_data(
        task_id=task_id,
        to_user_id=to_user_id,
        to_user_name=to_user_name,
    )

    kb = keyboards.delegation_reason_options(task_id, to_user_id)
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer(
        messages.delegation_reason_prompt(task_id, to_user_name),
        reply_markup=kb,
    )
    await callback.answer()


@router.message(DelegationReason.waiting, F.text)
async def on_delegation_reason_text(
    message: Message,
    session: UserSession,
    state: FSMContext,
) -> None:
    """Получена причина делегирования — создать запрос."""
    data = await state.get_data()
    await state.clear()

    reason = message.text.strip()
    api = TaskMateAPI(token=session.token)
    try:
        await api.create_delegation(
            data["task_id"],
            data["to_user_id"],
            reason=reason,
        )
    except httpx.HTTPStatusError as e:
        error_msg = "Ошибка"
        try:
            error_msg = e.response.json().get("message", error_msg)
        except Exception:
            pass
        await message.answer(f"❌ {error_msg}")
        return
    except Exception:
        logger.exception("Ошибка создания делегирования")
        await message.answer(messages.error_generic())
        return

    await message.answer(
        messages.delegation_created_success(data["task_id"], data["to_user_name"])
    )


@router.callback_query(F.data.startswith("dlg_skip:"))
async def cb_delegation_skip_reason(
    callback: CallbackQuery,
    session: UserSession,
    state: FSMContext,
) -> None:
    """Пропустить причину — создать делегацию без причины."""
    parts = callback.data.split(":")
    task_id = int(parts[1])
    to_user_id = int(parts[2])

    # Извлечь имя из FSM data до очистки
    data = await state.get_data()
    to_user_name = data.get("to_user_name", "сотрудник")
    await state.clear()

    api = TaskMateAPI(token=session.token)
    try:
        await api.create_delegation(task_id, to_user_id)
    except httpx.HTTPStatusError as e:
        error_msg = "Ошибка"
        try:
            error_msg = e.response.json().get("message", error_msg)
        except Exception:
            pass
        await callback.message.answer(f"❌ {error_msg}")
        await callback.answer()
        return
    except Exception:
        logger.exception("Ошибка создания делегирования")
        await callback.message.answer(messages.error_generic())
        await callback.answer()
        return

    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer(
        messages.delegation_created_success(task_id, to_user_name)
    )
    await callback.answer()


@router.callback_query(F.data.startswith("dlg_cancel_flow:"))
async def cb_delegation_cancel_flow(
    callback: CallbackQuery,
    state: FSMContext,
) -> None:
    """Отмена процесса создания делегации."""
    await state.clear()
    try:
        await callback.message.edit_text("Делегирование отменено.", reply_markup=None)
    except Exception:
        await callback.message.answer("Делегирование отменено.")
    await callback.answer()


# --- Принятие / Отклонение (target employee) ---


@router.callback_query(F.data.startswith("dlg_accept:"))
async def cb_delegation_accept(
    callback: CallbackQuery,
    session: UserSession,
) -> None:
    """Принять делегирование."""
    delegation_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)
    try:
        await api.accept_delegation(delegation_id)
    except httpx.HTTPStatusError as e:
        error_msg = "Ошибка"
        try:
            error_msg = e.response.json().get("message", error_msg)
        except Exception:
            pass
        await callback.answer(error_msg, show_alert=True)
        return
    except Exception:
        await callback.answer("Ошибка", show_alert=True)
        return

    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer(messages.delegation_accept_success(delegation_id))
    await callback.answer("✅")


@router.callback_query(F.data.startswith("dlg_reject:"))
async def cb_delegation_reject_start(
    callback: CallbackQuery,
    state: FSMContext,
) -> None:
    """Начать отклонение — запросить причину."""
    delegation_id = int(callback.data.split(":")[1])
    await state.set_state(DelegationRejectReason.waiting)
    await state.update_data(delegation_id=delegation_id)

    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    kb = keyboards.delegation_reject_cancel()
    await callback.message.answer(
        messages.delegation_reject_reason_prompt(),
        reply_markup=kb,
    )
    await callback.answer()


@router.message(DelegationRejectReason.waiting, F.text)
async def on_delegation_reject_reason(
    message: Message,
    session: UserSession,
    state: FSMContext,
) -> None:
    """Получена причина — отклонить делегирование."""
    data = await state.get_data()
    delegation_id = data["delegation_id"]
    reason = message.text.strip()
    await state.clear()

    api = TaskMateAPI(token=session.token)
    try:
        await api.reject_delegation(delegation_id, reason)
    except httpx.HTTPStatusError as e:
        error_msg = "Ошибка"
        try:
            error_msg = e.response.json().get("message", error_msg)
        except Exception:
            pass
        await message.answer(f"❌ {error_msg}")
        return
    except Exception:
        await message.answer(messages.error_generic())
        return

    await message.answer(messages.delegation_reject_success(delegation_id))


@router.callback_query(F.data == "dlg_reject_cancel")
async def cb_delegation_reject_cancel(
    callback: CallbackQuery,
    state: FSMContext,
) -> None:
    """Отмена ввода причины отклонения."""
    await state.clear()
    try:
        await callback.message.edit_text("Отклонение отменено.", reply_markup=None)
    except Exception:
        await callback.message.answer("Отклонение отменено.")
    await callback.answer()


# --- Отмена исходящей делегации (initiator) ---


@router.callback_query(F.data.startswith("dlg_cancel:"))
async def cb_delegation_cancel(
    callback: CallbackQuery,
    session: UserSession,
) -> None:
    """Отменить исходящую делегацию."""
    delegation_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)
    try:
        await api.cancel_delegation(delegation_id)
    except httpx.HTTPStatusError as e:
        error_msg = "Ошибка"
        try:
            error_msg = e.response.json().get("message", error_msg)
        except Exception:
            pass
        await callback.answer(error_msg, show_alert=True)
        return
    except Exception:
        await callback.answer("Ошибка", show_alert=True)
        return

    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer(messages.delegation_cancel_success(delegation_id))
    await callback.answer()


# --- FSM fallback ---


@router.message(DelegationReason.waiting)
async def on_delegation_reason_unexpected(
    message: Message,
    state: FSMContext,
) -> None:
    """Неожиданное сообщение при ожидании причины делегирования."""
    data = await state.get_data()
    kb = keyboards.delegation_reason_options(data["task_id"], data["to_user_id"])
    await message.answer(
        "Введите текст причины или нажмите «Пропустить».",
        reply_markup=kb,
    )


@router.message(DelegationRejectReason.waiting)
async def on_delegation_reject_reason_unexpected(
    message: Message,
    state: FSMContext,
) -> None:
    """Неожиданное сообщение при ожидании причины отклонения."""
    kb = keyboards.delegation_reject_cancel()
    await message.answer(
        "Введите текст причины отклонения.",
        reply_markup=kb,
    )
