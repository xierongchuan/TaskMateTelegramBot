"""Точка входа RabbitMQ worker: слушает события задач и отправляет Telegram-уведомления."""

from __future__ import annotations

import asyncio
import logging

from src.api.client import close_http_client
from src.bot.bot import bot
from src.config import settings
from src.rabbitmq.consumer import start_consumer
from src.storage import sessions

logging.basicConfig(
    level=getattr(logging, settings.log_level.upper(), logging.INFO),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)


async def main() -> None:
    logger.info("Запуск TaskMate Notification Worker...")

    try:
        while True:
            try:
                await start_consumer(bot)
            except asyncio.CancelledError:
                raise
            except Exception:
                logger.exception("RabbitMQ consumer упал, переподключение через 5 сек...")
                await asyncio.sleep(5)
    finally:
        logger.info("Остановка Notification Worker...")
        await close_http_client()
        await sessions.close()
        await bot.session.close()


if __name__ == "__main__":
    asyncio.run(main())
