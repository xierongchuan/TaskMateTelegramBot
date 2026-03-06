"""Обработчики /start и /help."""

from aiogram import Router
from aiogram.filters import Command
from aiogram.types import Message

from src.bot import keyboards, messages
from src.storage.sessions import get_session

router = Router()


@router.message(Command("start"))
async def cmd_start(message: Message) -> None:
    session = await get_session(message.chat.id)
    if session is not None:
        kb = keyboards.main_menu(session.role)
        await message.answer(messages.welcome_back(session.full_name, session.role), reply_markup=kb)
    else:
        await message.answer(messages.welcome())


@router.message(Command("help"))
async def cmd_help(message: Message) -> None:
    await message.answer(messages.help_text())
