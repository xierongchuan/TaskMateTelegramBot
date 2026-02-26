"""Инициализация aiogram Bot и Dispatcher."""

from __future__ import annotations

import logging
from typing import Any, Callable, Awaitable

from aiogram import BaseMiddleware, Bot, Dispatcher, Router
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.types import Message, CallbackQuery, TelegramObject

import httpx

from src.bot import keyboards
from src.config import settings
from src.storage.notifications import clear_notified
from src.storage.sessions import delete_session, get_session, refresh_session_ttl

logger = logging.getLogger(__name__)

bot = Bot(
    token=settings.telegram_bot_token,
    default=DefaultBotProperties(parse_mode=ParseMode.HTML),
)
dp = Dispatcher()


class AuthMiddleware(BaseMiddleware):
    """Middleware: проверяет авторизацию перед обработкой.

    Пропускает без проверки: /start, /help, /login.
    """

    SKIP_COMMANDS = {"/start", "/help", "/login"}

    async def __call__(
        self,
        handler: Callable[[TelegramObject, dict[str, Any]], Awaitable[Any]],
        event: TelegramObject,
        data: dict[str, Any],
    ) -> Any:
        # Определить chat_id и текст команды
        chat_id: int | None = None
        text: str = ""

        if isinstance(event, Message):
            chat_id = event.chat.id
            text = event.text or ""
        elif isinstance(event, CallbackQuery):
            chat_id = event.message.chat.id if event.message else None

        # Пропуск для публичных команд
        if text:
            cmd = text.split()[0].split("@")[0]
            if cmd in self.SKIP_COMMANDS:
                return await handler(event, data)

        # Проверка сессии
        if chat_id is not None:
            session = await get_session(chat_id)
            if session is None:
                if isinstance(event, Message):
                    from src.bot import messages
                    await event.answer(messages.not_authorized())
                elif isinstance(event, CallbackQuery):
                    await event.answer("Вы не авторизованы", show_alert=True)
                return
            data["session"] = session
            await refresh_session_ttl(chat_id)

        try:
            return await handler(event, data)
        except httpx.HTTPStatusError as e:
            if e.response.status_code == 401 and chat_id is not None:
                await delete_session(chat_id)
                await clear_notified(chat_id)
                logger.info("Сессия просрочена для chat_id=%s, сессия удалена", chat_id)

                from src.bot import messages
                if isinstance(event, Message):
                    await event.answer(
                        messages.not_authorized(),
                        reply_markup=keyboards.remove_menu(),
                    )
                elif isinstance(event, CallbackQuery):
                    await event.answer("Сессия истекла. Используйте /login", show_alert=True)
                return
            raise


class ReplyKeyboardMiddleware(BaseMiddleware):
    """Передаёт reply_keyboard через data для автоматического прикрепления меню."""

    async def __call__(
        self,
        handler: Callable[[TelegramObject, dict[str, Any]], Awaitable[Any]],
        event: TelegramObject,
        data: dict[str, Any],
    ) -> Any:
        session = data.get("session")
        if session:
            data["reply_keyboard"] = keyboards.main_menu(session.role)
        return await handler(event, data)
