"""Точка входа TaskMateBot: запуск aiogram + APScheduler."""

from __future__ import annotations

import asyncio
import logging

from apscheduler import AsyncScheduler
from apscheduler.triggers.interval import IntervalTrigger

from src.api.client import close_http_client
from src.bot.bot import AuthMiddleware, ReplyKeyboardMiddleware, bot, dp
from src.bot.handlers import auth, common, delegations, menu, review, shifts, tasks
from src.config import settings
from src.scheduler.polling import check_deadlines
from src.storage import sessions

logging.basicConfig(
    level=getattr(logging, settings.log_level.upper(), logging.INFO),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)


async def main() -> None:
    logger.info("Запуск TaskMateBot...")

    # Регистрация роутеров (common без auth middleware, остальные с ним)
    dp.include_router(common.router)

    # Роутеры, требующие авторизации
    for r in (auth.router, tasks.router, shifts.router, review.router, delegations.router, menu.router):
        r.message.middleware(AuthMiddleware())
        r.message.middleware(ReplyKeyboardMiddleware())
        r.callback_query.middleware(AuthMiddleware())
        r.callback_query.middleware(ReplyKeyboardMiddleware())
        dp.include_router(r)

    # Запуск scheduler для polling дедлайнов
    async with AsyncScheduler() as scheduler:
        await scheduler.add_schedule(
            check_deadlines,
            IntervalTrigger(seconds=settings.polling_interval_deadlines),
            id="check_deadlines",
            kwargs={"bot": bot},
        )
        await scheduler.start_in_background()

        logger.info("Scheduler дедлайнов запущен")

        try:
            await dp.start_polling(bot)
        finally:
            logger.info("Остановка TaskMateBot...")
            await close_http_client()
            await sessions.close()
            await bot.session.close()


if __name__ == "__main__":
    asyncio.run(main())
