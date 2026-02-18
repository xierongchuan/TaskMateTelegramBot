"""Обработчики смен: /shift, /shifts, открытие/закрытие через FSM."""

from __future__ import annotations

import logging
from typing import Any

from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import BufferedInputFile, CallbackQuery, Message

import httpx

from src.api.client import TaskMateAPI
from src.bot import keyboards, messages
from src.storage.sessions import UserSession

logger = logging.getLogger(__name__)
router = Router()


# --- FSM States ---


class ShiftOpen(StatesGroup):
    """FSM: открытие смены."""
    selecting_dealership = State()
    waiting_photo = State()


class ShiftClose(StatesGroup):
    """FSM: закрытие смены."""
    waiting_photo = State()


# --- Команды (read-only) ---


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


# --- Открытие смены ---


@router.callback_query(F.data == "shift_open")
async def cb_shift_open(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Начать процесс открытия смены."""
    api = TaskMateAPI(token=session.token)

    # Проверить, нет ли уже открытой смены
    try:
        result = await api.get_my_current_shift()
        if result.get("data"):
            await callback.answer("У вас уже есть открытая смена", show_alert=True)
            return
    except httpx.HTTPStatusError as e:
        if e.response.status_code != 404:
            await callback.answer("Ошибка проверки смены", show_alert=True)
            return
    except Exception:
        await callback.answer("Ошибка проверки смены", show_alert=True)
        return

    # Получить автосалоны
    try:
        dealerships = await api.get_user_dealerships()
    except Exception:
        await callback.answer("Ошибка получения автосалонов", show_alert=True)
        return

    if not dealerships:
        await callback.answer("Нет доступных автосалонов", show_alert=True)
        return

    if len(dealerships) == 1:
        # Один автосалон — сразу к фото
        await state.set_state(ShiftOpen.waiting_photo)
        await state.update_data(dealership_id=dealerships[0]["id"])
        kb = keyboards.shift_open_cancel()
        await callback.message.answer(messages.shift_open_photo_prompt(), reply_markup=kb)
    else:
        # Несколько — выбрать
        await state.set_state(ShiftOpen.selecting_dealership)
        kb = keyboards.dealership_selector(dealerships)
        await callback.message.answer(messages.shift_select_dealership(), reply_markup=kb)

    await callback.answer()


@router.callback_query(ShiftOpen.selecting_dealership, F.data.startswith("shift_dealer:"))
async def cb_shift_select_dealership(callback: CallbackQuery, state: FSMContext) -> None:
    """Выбрать автосалон для открытия смены."""
    dealership_id = int(callback.data.split(":")[1])
    await state.set_state(ShiftOpen.waiting_photo)
    await state.update_data(dealership_id=dealership_id)
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    kb = keyboards.shift_open_cancel()
    await callback.message.answer(messages.shift_open_photo_prompt(), reply_markup=kb)
    await callback.answer()


@router.callback_query(F.data == "shift_open_cancel")
async def cb_shift_open_cancel(callback: CallbackQuery, state: FSMContext) -> None:
    """Отменить открытие смены."""
    await state.clear()
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer("Открытие смены отменено.")
    await callback.answer()


@router.message(ShiftOpen.waiting_photo, F.photo)
async def on_shift_open_photo(
    message: Message, session: UserSession, state: FSMContext
) -> None:
    """Получить фото и открыть смену."""
    data = await state.get_data()
    dealership_id = data["dealership_id"]

    # Скачать фото
    photo = message.photo[-1]
    file = await message.bot.get_file(photo.file_id)
    file_bytes = await message.bot.download_file(file.file_path)
    content = file_bytes.read()

    api = TaskMateAPI(token=session.token)
    try:
        result = await api.open_shift(
            user_id=session.user_id,
            dealership_id=dealership_id,
            photo=("opening_photo.jpg", content, "image/jpeg"),
        )
    except httpx.HTTPStatusError as e:
        error_detail = ""
        try:
            body = e.response.json()
            error_detail = body.get("message", "")
        except Exception:
            pass
        await state.clear()
        await message.answer(
            f"❌ Не удалось открыть смену.{f' {error_detail}' if error_detail else ''}"
        )
        return
    except Exception:
        logger.exception("Ошибка при открытии смены")
        await state.clear()
        await message.answer(messages.error_generic())
        return

    await state.clear()
    shift = result.get("data", result)
    await message.answer(messages.shift_opened_success(shift))


# --- Закрытие смены ---


@router.callback_query(F.data.startswith("shift_close:"))
async def cb_shift_close(callback: CallbackQuery, state: FSMContext) -> None:
    """Начать процесс закрытия смены."""
    shift_id = int(callback.data.split(":")[1])
    await state.set_state(ShiftClose.waiting_photo)
    await state.update_data(shift_id=shift_id)
    kb = keyboards.shift_close_options(shift_id)
    await callback.message.answer(messages.shift_close_photo_prompt(), reply_markup=kb)
    await callback.answer()


@router.callback_query(F.data.startswith("shift_close_nophoto:"))
async def cb_shift_close_nophoto(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Закрыть смену без фото."""
    shift_id = int(callback.data.split(":")[1])
    await state.clear()

    api = TaskMateAPI(token=session.token)
    try:
        result = await api.close_shift(shift_id)
    except httpx.HTTPStatusError as e:
        error_detail = ""
        try:
            body = e.response.json()
            error_detail = body.get("message", "")
        except Exception:
            pass
        await callback.answer(
            f"Ошибка закрытия смены{f': {error_detail}' if error_detail else ''}",
            show_alert=True,
        )
        return
    except Exception:
        logger.exception("Ошибка при закрытии смены")
        await callback.answer("Ошибка закрытия смены", show_alert=True)
        return

    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    shift = result.get("data", result)
    await callback.message.answer(messages.shift_closed_success(shift))
    await callback.answer()


@router.callback_query(F.data == "shift_close_cancel")
async def cb_shift_close_cancel(callback: CallbackQuery, state: FSMContext) -> None:
    """Отменить закрытие смены."""
    await state.clear()
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer("Закрытие смены отменено.")
    await callback.answer()


@router.message(ShiftClose.waiting_photo, F.photo)
async def on_shift_close_photo(
    message: Message, session: UserSession, state: FSMContext
) -> None:
    """Получить фото и закрыть смену."""
    data = await state.get_data()
    shift_id = data["shift_id"]

    # Скачать фото
    photo = message.photo[-1]
    file = await message.bot.get_file(photo.file_id)
    file_bytes = await message.bot.download_file(file.file_path)
    content = file_bytes.read()

    api = TaskMateAPI(token=session.token)
    try:
        result = await api.close_shift(
            shift_id,
            photo=("closing_photo.jpg", content, "image/jpeg"),
        )
    except httpx.HTTPStatusError as e:
        error_detail = ""
        try:
            body = e.response.json()
            error_detail = body.get("message", "")
        except Exception:
            pass
        await state.clear()
        await message.answer(
            f"❌ Не удалось закрыть смену.{f' {error_detail}' if error_detail else ''}"
        )
        return
    except Exception:
        logger.exception("Ошибка при закрытии смены")
        await state.clear()
        await message.answer(messages.error_generic())
        return

    await state.clear()
    shift = result.get("data", result)
    await message.answer(messages.shift_closed_success(shift))


# --- FSM Fallbacks ---


@router.message(ShiftOpen.waiting_photo)
async def on_shift_open_unexpected(message: Message, state: FSMContext) -> None:
    """Напомнить отправить фото при открытии смены."""
    kb = keyboards.shift_open_cancel()
    await message.answer(
        "Пожалуйста, отправьте <b>фото</b> для открытия смены.",
        reply_markup=kb,
    )


@router.message(ShiftOpen.selecting_dealership)
async def on_shift_dealership_unexpected(message: Message, state: FSMContext) -> None:
    """Напомнить выбрать автосалон."""
    await message.answer("Пожалуйста, выберите автосалон из списка выше.")


@router.message(ShiftClose.waiting_photo)
async def on_shift_close_unexpected(message: Message, state: FSMContext) -> None:
    """Напомнить отправить фото или нажать «Без фото»."""
    data = await state.get_data()
    kb = keyboards.shift_close_options(data["shift_id"])
    await message.answer(
        "Отправьте <b>фото</b> или нажмите «Без фото».",
        reply_markup=kb,
    )
