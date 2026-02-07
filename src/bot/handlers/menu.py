"""Обработчики кнопок главного меню (ReplyKeyboard)."""

from __future__ import annotations

import asyncio
import logging

from aiogram import F, Router
from aiogram.types import Message, ReplyKeyboardMarkup

import httpx

from src.api.client import TaskMateAPI
from src.bot import keyboards, messages
from src.storage.notifications import clear_notified
from src.storage.sessions import UserSession, delete_session, get_session

logger = logging.getLogger(__name__)
router = Router()


def _kb(data: dict) -> ReplyKeyboardMarkup | None:
    """Получить reply_keyboard из middleware data."""
    return data.get("reply_keyboard")


@router.message(F.text.in_({keyboards.BTN_MY_TASKS, keyboards.BTN_TASKS}))
async def btn_tasks(message: Message, session: UserSession, **kwargs) -> None:
    """Список задач за сегодня."""
    kb = _kb(kwargs)
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_tasks({"date_range": "today", "per_page": 20})
    except httpx.HTTPStatusError:
        raise
    except Exception:
        logger.exception("Ошибка при получении задач")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    tasks = result.get("data", [])
    if not tasks:
        await message.answer("📋 У вас нет активных задач.", reply_markup=kb)
        return

    await message.answer(f"📋 <b>Задачи на сегодня ({len(tasks)})</b>", reply_markup=kb)
    for t in tasks:
        text = messages.task_list_item_text(t)
        item_kb = keyboards.task_list_item(t["id"])
        await message.answer(text, reply_markup=item_kb)
        await asyncio.sleep(0.05)


@router.message(F.text == keyboards.BTN_MY_SHIFT)
async def btn_my_shift(message: Message, session: UserSession, **kwargs) -> None:
    """Текущая смена."""
    kb = _kb(kwargs)
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_my_current_shift()
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 404:
            await message.answer(messages.no_current_shift(), reply_markup=kb)
            return
        raise
    except Exception:
        logger.exception("Ошибка при получении смены")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    shift = result.get("data")
    if not shift:
        await message.answer(messages.no_current_shift(), reply_markup=kb)
        return
    await message.answer(messages.shift_info(shift), reply_markup=kb)


@router.message(F.text == keyboards.BTN_SHIFTS)
async def btn_shifts(message: Message, session: UserSession, **kwargs) -> None:
    """Смены — для менеджеров показать открытые с фото, для остальных — свои."""
    kb = _kb(kwargs)
    if session.role in ("manager", "owner"):
        from src.bot.handlers.shifts import send_manager_shifts
        await send_manager_shifts(message, session, reply_kb=kb)
    else:
        api = TaskMateAPI(token=session.token)
        try:
            result = await api.get_my_shifts({"per_page": 10})
        except httpx.HTTPStatusError:
            raise
        except Exception:
            logger.exception("Ошибка при получении смен")
            await message.answer(messages.error_generic(), reply_markup=kb)
            return

        shifts = result.get("data", [])
        await message.answer(messages.shift_list(shifts), reply_markup=kb)


@router.message(F.text == keyboards.BTN_DASHBOARD)
async def btn_dashboard(message: Message, session: UserSession, **kwargs) -> None:
    """Дашборд."""
    kb = _kb(kwargs)
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_dashboard()
    except httpx.HTTPStatusError:
        raise
    except Exception:
        logger.exception("Ошибка при получении дашборда")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    data = result.get("data", result)
    await message.answer(messages.dashboard_summary(data, role=session.role), reply_markup=kb)


@router.message(F.text == keyboards.BTN_PENDING_REVIEW)
async def btn_pending_review(message: Message, session: UserSession, **kwargs) -> None:
    """Задачи на проверку (manager/owner) — каждая отдельным сообщением."""
    from src.bot.handlers.review import send_review_list
    await send_review_list(message, session, reply_kb=_kb(kwargs))


@router.message(F.text == keyboards.BTN_OVERDUE)
async def btn_overdue(message: Message, session: UserSession, **kwargs) -> None:
    """Просроченные задачи (manager/owner)."""
    kb = _kb(kwargs)
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_tasks({"status": "overdue", "per_page": 20})
    except httpx.HTTPStatusError:
        raise
    except Exception:
        logger.exception("Ошибка при получении просроченных задач")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    tasks = result.get("data", [])
    await message.answer(messages.overdue_task_list(tasks), reply_markup=kb)


@router.message(F.text == keyboards.BTN_LOGOUT)
async def btn_logout(message: Message, **kwargs) -> None:
    """Выход через кнопку меню."""
    session = await get_session(message.chat.id)
    if session is None:
        await message.answer(messages.not_authorized())
        return

    api = TaskMateAPI(token=session.token)
    try:
        await api.logout()
    except Exception:
        pass

    await clear_notified(message.chat.id)
    await delete_session(message.chat.id)
    await message.answer(messages.logout_success(), reply_markup=keyboards.remove_menu())
