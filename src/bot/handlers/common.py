"""Обработчики /start и /help."""

from aiogram import Router
from aiogram.filters import Command
from aiogram.types import Message

from src.bot import messages

router = Router()


@router.message(Command("start"))
async def cmd_start(message: Message) -> None:
    await message.answer(messages.welcome())


@router.message(Command("help"))
async def cmd_help(message: Message) -> None:
    await message.answer(messages.help_text())
