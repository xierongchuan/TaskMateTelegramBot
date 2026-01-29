"""Инициализация aiogram Bot и Dispatcher."""

from __future__ import annotations

import logging
from typing import Any, Callable, Awaitable

from aiogram import BaseMiddleware, Bot, Dispatcher, Router
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.types import Message, CallbackQuery, TelegramObject

from src.config import settings
from src.storage.sessions import get_session

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

        return await handler(event, data)
