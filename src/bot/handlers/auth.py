"""Обработчики /login и /logout."""

from __future__ import annotations

import logging

from aiogram import Router
from aiogram.filters import Command
from aiogram.types import Message

import httpx

from src.api.client import TaskMateAPI
from src.bot import keyboards, messages
from src.storage.notifications import bulk_add_notified, clear_notified
from src.storage.sessions import UserSession, delete_session, get_session, save_session

logger = logging.getLogger(__name__)
router = Router()


@router.message(Command("login"))
async def cmd_login(message: Message) -> None:
    # Удалить сообщение с паролем
    try:
        await message.delete()
    except Exception:
        pass

    args = (message.text or "").split(maxsplit=2)
    if len(args) < 3:
        await message.answer(messages.login_usage())
        return

    _, login, password = args

    # Проверить, не авторизован ли уже
    existing = await get_session(message.chat.id)
    if existing:
        await message.answer(
            f"Вы уже авторизованы как <b>{existing.full_name}</b>.\n"
            "Используйте /logout для выхода."
        )
        return

    api = TaskMateAPI()
    try:
        result = await api.login(login, password)
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 401:
            await message.answer(messages.login_failed("Неверный логин или пароль"))
        elif e.response.status_code == 429:
            await message.answer(messages.login_failed("Слишком много попыток. Попробуйте позже"))
        else:
            await message.answer(messages.login_failed())
        return
    except Exception:
        logger.exception("Ошибка при авторизации")
        await message.answer(messages.error_generic())
        return

    token = result.get("token", "")
    user = result.get("user", {})

    session = UserSession(
        token=token,
        user_id=user.get("id", 0),
        full_name=user.get("full_name", ""),
        role=user.get("role", ""),
        login=login,
    )
    await save_session(message.chat.id, session)

    # Затушить текущие задачи, чтобы не отправлять старые уведомления
    try:
        authed_api = TaskMateAPI(token=token)
        tasks_result = await authed_api.get_tasks({"per_page": 100})
        task_ids = [t["id"] for t in tasks_result.get("data", [])]
        if task_ids:
            for category in ("tasks", "deadlines", "overdue", "reviews"):
                await bulk_add_notified(message.chat.id, category, task_ids)
    except Exception:
        logger.debug("Не удалось затушить уведомления при логине для %s", message.chat.id)

    await message.answer(
        messages.login_success(session.full_name, session.role),
        reply_markup=keyboards.main_menu(session.role),
    )


@router.message(Command("logout"))
async def cmd_logout(message: Message, **kwargs) -> None:
    session = await get_session(message.chat.id)
    if session is None:
        await message.answer(messages.not_authorized())
        return

    # Вызвать API logout
    api = TaskMateAPI(token=session.token)
    try:
        await api.logout()
    except Exception:
        pass

    await clear_notified(message.chat.id)
    await delete_session(message.chat.id)
    await message.answer(messages.logout_success(), reply_markup=keyboards.remove_menu())
