"""Обработчики смен: /shift, /shifts."""

from __future__ import annotations

import logging
from typing import Any

from aiogram import Router
from aiogram.filters import Command
from aiogram.types import BufferedInputFile, Message

import httpx

from src.api.client import TaskMateAPI
from src.bot import messages
from src.storage.sessions import UserSession

logger = logging.getLogger(__name__)
router = Router()


@router.message(Command("shift"))
async def cmd_shift(message: Message, session: UserSession, **kwargs) -> None:
    """Текущая смена."""
    kb = kwargs.get("reply_keyboard")
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


@router.message(Command("shifts"))
async def cmd_shifts(message: Message, session: UserSession, **kwargs) -> None:
    """Мои смены."""
    kb = kwargs.get("reply_keyboard")
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


async def send_manager_shifts(
    message: Message, session: UserSession, reply_kb: Any = None
) -> None:
    """Показать открытые смены сегодня — каждая отдельным сообщением с фото."""
    api = TaskMateAPI(token=session.token)
    try:
        from datetime import datetime, timezone

        today_utc = datetime.now(timezone.utc).date().isoformat()
        result = await api.get_shifts({
            "status": "open",
            "date": today_utc,
            "per_page": 20,
        })
    except httpx.HTTPStatusError:
        raise
    except Exception:
        logger.exception("Ошибка при получении смен")
        await message.answer(messages.error_generic(), reply_markup=reply_kb)
        return

    shifts = result.get("data", [])
    if not shifts:
        await message.answer(messages.no_open_shifts(), reply_markup=reply_kb)
        return

    for shift in shifts:
        text = messages.shift_card_for_manager(shift)

        # Попытаться отправить с фото открытия
        photo_sent = False
        shift_id = shift.get("id")
        if shift_id:
            try:
                photo_bytes = await api.download_shift_photo(shift_id, "opening")
                if photo_bytes:
                    photo = BufferedInputFile(photo_bytes, filename="opening.jpg")
                    await message.answer_photo(photo=photo, caption=text)
                    photo_sent = True
            except Exception:
                logger.debug("Не удалось отправить фото смены %s", shift_id)

        if not photo_sent:
            await message.answer(text)
