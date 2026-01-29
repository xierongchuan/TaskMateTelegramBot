"""Обработчики смен: /shift, /shifts."""

from __future__ import annotations

import logging

from aiogram import Router
from aiogram.filters import Command
from aiogram.types import Message

import httpx

from src.api.client import TaskMateAPI
from src.bot import messages
from src.storage.sessions import UserSession

logger = logging.getLogger(__name__)
router = Router()


@router.message(Command("shift"))
async def cmd_shift(message: Message, session: UserSession) -> None:
    """Текущая смена."""
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_my_current_shift()
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 404:
            await message.answer(messages.no_current_shift())
        elif e.response.status_code == 401:
            await message.answer(messages.not_authorized())
        else:
            await message.answer(messages.error_generic())
        return
    except Exception:
        logger.exception("Ошибка при получении смены")
        await message.answer(messages.error_generic())
        return

    shift = result.get("data")
    if not shift:
        await message.answer(messages.no_current_shift())
        return
    await message.answer(messages.shift_info(shift))


@router.message(Command("shifts"))
async def cmd_shifts(message: Message, session: UserSession) -> None:
    """Мои смены."""
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_my_shifts({"per_page": 10})
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 401:
            await message.answer(messages.not_authorized())
        else:
            await message.answer(messages.error_generic())
        return
    except Exception:
        logger.exception("Ошибка при получении смен")
        await message.answer(messages.error_generic())
        return

    shifts = result.get("data", [])
    await message.answer(messages.shift_list(shifts))
