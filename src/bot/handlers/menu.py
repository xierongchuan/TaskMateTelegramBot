"""Обработчики кнопок главного меню (ReplyKeyboard)."""

from __future__ import annotations

import asyncio
import logging

import httpx
from aiogram import F, Router
from aiogram.types import Message, ReplyKeyboardMarkup

from ...api.client import TaskMateAPI
from ...storage.notifications import clear_notified
from ...storage.sessions import UserSession, delete_session, get_session
from ...utils.tz_utils import attach_dealership_timezone
from .. import keyboards, messages

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
    # Build params based on which button was pressed and the user's role:
    # - If user pressed `BTN_MY_TASKS` or is an employee -> show only assigned tasks
    # - If user pressed `BTN_TASKS` and is manager -> show tasks for manager's dealerships
    # - If user pressed `BTN_TASKS` and is owner -> show all tasks in system
    params: dict = {"date_range": "today", "per_page": 20}
    text = (message.text or "").strip()
    if text == keyboards.BTN_MY_TASKS or session.role == "employee":
        params["assigned_to"] = session.user_id

    try:
        result = await api.get_tasks(params)
    except httpx.HTTPStatusError:
        raise
    except Exception:
        logger.exception("Ошибка при получении задач")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    tasks = result.get("data", [])
    # Для менеджеров/владельцев при просмотре общего списка задач
    # исключаем задачи со статусом `pending_review`, т.к. для них
    # есть отдельная кнопка "На проверку".
    text = (message.text or "").strip()
    if text == keyboards.BTN_TASKS and session.role in ("manager", "owner") and tasks:
        original_count = len(tasks)
        tasks = [t for t in tasks if t.get("status") != "pending_review"]
        logger.info(
            "btn_tasks: role=%s — filtered out %d tasks with status=pending_review",
            session.role,
            original_count - len(tasks),
        )

    if not tasks:
        await message.answer("📋 У вас нет активных задач.", reply_markup=kb)
        return
    # Ensure tasks have dealership.timezone attached for proper local formatting
    if tasks:
        try:
            await asyncio.gather(*(attach_dealership_timezone(api, t) for t in tasks))
        except Exception:
            logger.debug("Не удалось прикрепить timezone для списка задач (menu)")

    await message.answer(f"📋 <b>Задачи на сегодня ({len(tasks)})</b>", reply_markup=kb)
    for t in tasks:
        text = messages.task_list_item_text(t)
        item_kb = keyboards.task_list_item(t["id"])
        await message.answer(text, reply_markup=item_kb)
        await asyncio.sleep(0.05)


@router.message(F.text == keyboards.BTN_MY_SHIFT)
async def btn_my_shift(message: Message, session: UserSession, **kwargs) -> None:
    """Текущая смена. Для employee — с кнопками открытия/закрытия."""
    kb = _kb(kwargs)
    is_employee = session.role == "employee"
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_my_current_shift()
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 404:
            if is_employee:
                await message.answer(
                    messages.no_current_shift_with_action(),
                    reply_markup=keyboards.shift_actions_no_shift(),
                )
            else:
                await message.answer(messages.no_current_shift(), reply_markup=kb)
            return
        raise
    except Exception:
        logger.exception("Ошибка при получении смены")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    shift = result.get("data")
    if not shift:
        if is_employee:
            await message.answer(
                messages.no_current_shift_with_action(),
                reply_markup=keyboards.shift_actions_no_shift(),
            )
        else:
            await message.answer(messages.no_current_shift(), reply_markup=kb)
        return

    # Ensure timezone attached for correct local display
    try:
        await attach_dealership_timezone(api, shift)
    except Exception:
        logger.debug("Не удалось прикрепить timezone для текущей смены %s", shift.get("id"))

    if is_employee and shift.get("status") in ("open", "late"):
        await message.answer(
            messages.shift_info_with_action(shift),
            reply_markup=keyboards.shift_actions_open(shift["id"]),
        )
    else:
        await message.answer(messages.shift_info(shift), reply_markup=kb)


@router.message(F.text == keyboards.BTN_SHIFTS)
async def btn_shifts(message: Message, session: UserSession, **kwargs) -> None:
    """Смены — для менеджеров показать открытые с фото, для остальных — свои."""
    kb = _kb(kwargs)
    if session.role in ("manager", "owner"):
        from .shifts import send_manager_shifts

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
        if shifts:
            try:
                await asyncio.gather(*(attach_dealership_timezone(api, s) for s in shifts))
            except Exception:
                logger.debug("Не удалось прикрепить timezone для списка смен (menu)")
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
    # Ensure tasks in dashboard have dealership.timezone attached (cached) so
    # messages.dashboard_summary formats deadlines in local TZ instead of UTC.
    overdue = data.get("overdue_tasks_list", [])
    if overdue:
        try:
            await asyncio.gather(*(attach_dealership_timezone(api, t) for t in overdue))
        except Exception:
            logger.debug("Не удалось прикрепить timezone для задач дашборда")
    await message.answer(
        messages.dashboard_summary(data, role=session.role), reply_markup=kb
    )


@router.message(F.text == keyboards.BTN_PENDING_REVIEW)
async def btn_pending_review(message: Message, session: UserSession, **kwargs) -> None:
    """Задачи на проверку (manager/owner) — каждая отдельным сообщением."""
    from .review import send_review_list

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
    if tasks:
        try:
            await asyncio.gather(*(attach_dealership_timezone(api, t) for t in tasks))
        except Exception:
            logger.debug("Не удалось прикрепить timezone для просроченных задач (menu)")
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
    await message.answer(
        messages.logout_success(), reply_markup=keyboards.remove_menu()
    )


@router.message(F.text == keyboards.BTN_DELEGATIONS)
async def btn_delegations(message: Message, session: UserSession, **kwargs) -> None:
    """Список всех делегирований (входящие + исходящие + история)."""
    kb = _kb(kwargs)
    api = TaskMateAPI(token=session.token)

    # Получаем все pending делегации
    try:
        result = await api.get_delegations({"status": "pending", "per_page": 50})
    except Exception:
        logger.exception("Ошибка при получении делегирований")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    delegations = result.get("data", [])

    # Разделяем на входящие и исходящие
    incoming = [
        d for d in delegations
        if d.get("to_user", {}).get("id") == session.user_id
    ]
    outgoing = [
        d for d in delegations
        if d.get("from_user", {}).get("id") == session.user_id
    ]

    # Показываем входящие с кнопками принятия/отклонения
    if incoming:
        await message.answer("📥 <b>Входящие запросы на делегирование</b>", reply_markup=kb)
        for d in incoming:
            dlg_id = d.get("id")
            task = d.get("task", {})
            from_user = d.get("from_user", {})
            from_name = from_user.get("full_name", "—")
            task_id = task.get("id", "?")
            task_title = task.get("title", "—")
            task_desc = task.get("description", "")
            deadline = task.get("deadline")
            priority = task.get("priority", "medium")
            reason = d.get("reason", "")

            priority_icon = {"low": "🟢", "medium": "🟡", "high": "🔴"}.get(priority, "⚪")

            task_info = f"{priority_icon} <b>Задача #{task_id}</b>: {task_title}"
            if task_desc:
                task_info += f"\n   <i>{task_desc[:60]}{'...' if len(task_desc) > 60 else ''}</i>"
            if deadline:
                from datetime import datetime
                try:
                    dt = datetime.fromisoformat(deadline.replace("Z", "+00:00"))
                    task_info += f"\n   📅 {dt.strftime('%d.%m.%Y %H:%M')}"
                except Exception:
                    pass
            if reason:
                task_info += f"\n   💬 {reason}"

            task_info += f"\n\n👤 От: {from_name}"

            task_kb = keyboards.delegation_incoming_actions(dlg_id)
            await message.answer(task_info, reply_markup=task_kb)
    else:
        await message.answer("📥 <b>Входящих нет</b>", reply_markup=kb)

    # Показываем исходящие с кнопкой отмены
    if outgoing:
        await message.answer("📤 <b>Исходящие запросы на делегирование</b>")
        for d in outgoing:
            dlg_id = d.get("id")
            task = d.get("task", {})
            to_user = d.get("to_user", {})
            to_name = to_user.get("full_name", "—")
            task_id = task.get("id", "?")
            task_title = task.get("title", "—")
            priority = task.get("priority", "medium")

            priority_icon = {"low": "🟢", "medium": "🟡", "high": "🔴"}.get(priority, "⚪")

            task_info = f"{priority_icon} <b>Задача #{task_id}</b>: {task_title}\n👤 Кому: {to_name}"

            task_kb = keyboards.delegation_cancel_button(dlg_id)
            await message.answer(task_info, reply_markup=task_kb)
    else:
        await message.answer("📤 <b>Исходящих нет</b>", reply_markup=kb)

    # История — каждое делегирование отдельным сообщением
    try:
        history_result = await api.get_delegations({"status": "accepted,rejected,cancelled", "per_page": 20})
        history = history_result.get("data", [])
    except Exception:
        history = []

    if history:
        await message.answer("📜 <b>История делегирований</b>", reply_markup=kb)
        for d in history:
            dlg_id = d.get("id")
            task = d.get("task", {})
            from_user = d.get("from_user", {})
            to_user = d.get("to_user", {})
            from_name = from_user.get("full_name", "—")
            to_name = to_user.get("full_name", "—")
            task_id = task.get("id", "?")
            task_title = task.get("title", "—")
            status = d.get("status", "")
            responded_at = d.get("responded_at")

            status_icon = {"accepted": "✅", "rejected": "❌", "cancelled": "🚫"}.get(status, "⚪")
            status_text = {"accepted": "Принято", "rejected": "Отклонено", "cancelled": "Отменено"}.get(status, status)

            history_info = f"{status_icon} <b>Задача #{task_id}</b>: {task_title}\n"
            history_info += f"   {from_name} → {to_name}\n"
            history_info += f"   📜 {status_text}"
            if responded_at:
                from datetime import datetime
                try:
                    dt = datetime.fromisoformat(responded_at.replace("Z", "+00:00"))
                    history_info += f" ({dt.strftime('%d.%m.%Y %H:%M')}"
                except Exception:
                    pass

            await message.answer(history_info)
    else:
        await message.answer("📜 <b>История пуста</b>", reply_markup=kb)
