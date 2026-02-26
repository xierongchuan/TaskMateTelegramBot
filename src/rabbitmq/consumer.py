"""RabbitMQ consumer для получения событий задач от backend."""

from __future__ import annotations

import json
import logging
import time

import aio_pika
from aiogram import Bot

from src.bot import keyboards, messages
from src.config import settings
from src.storage.notifications import add_notified, is_notified
from src.storage.sessions import UserSession, get_all_sessions

logger = logging.getLogger(__name__)

EXCHANGE_NAME = "task_events"
QUEUE_NAME = "telegram_notifications"

# Кэш сессий: обновляется не чаще раз в 60 секунд
_sessions_cache: dict[int, UserSession] = {}
_cache_updated_at: float = 0.0
_CACHE_TTL = 60.0


async def _get_cached_sessions() -> dict[int, UserSession]:
    """Получить сессии с кэшированием."""
    global _sessions_cache, _cache_updated_at
    now = time.monotonic()
    if now - _cache_updated_at > _CACHE_TTL:
        _sessions_cache = await get_all_sessions()
        _cache_updated_at = now
    return _sessions_cache


async def start_consumer(bot: Bot) -> None:
    """Запустить RabbitMQ consumer. Блокирующий — запускать как asyncio task."""
    url = (
        f"amqp://{settings.rabbitmq_user}:{settings.rabbitmq_password}"
        f"@{settings.rabbitmq_host}:{settings.rabbitmq_port}{settings.rabbitmq_vhost}"
    )

    connection = await aio_pika.connect_robust(url)
    channel = await connection.channel()
    await channel.set_qos(prefetch_count=10)

    exchange = await channel.declare_exchange(
        EXCHANGE_NAME, aio_pika.ExchangeType.FANOUT, durable=True
    )
    queue = await channel.declare_queue(QUEUE_NAME, durable=True)
    await queue.bind(exchange)

    logger.info("RabbitMQ consumer запущен, слушаю %s", QUEUE_NAME)

    async with queue.iterator() as iter_:
        async for msg in iter_:
            async with msg.process():
                try:
                    await _handle_message(bot, msg.body)
                except Exception:
                    logger.exception("Ошибка обработки RabbitMQ сообщения")


async def _handle_message(bot: Bot, body: bytes) -> None:
    """Обработать одно сообщение из RabbitMQ."""
    payload = json.loads(body)
    event = payload.get("event", "")
    task = payload.get("task", {})
    user_ids = payload.get("user_ids", [])
    task_id = task.get("id")

    if not task_id or not user_ids:
        return

    # Найти chat_id по user_id из кэшированных сессий
    sessions = await _get_cached_sessions()
    user_to_chat: dict[int, int] = {
        s.user_id: chat_id for chat_id, s in sessions.items()
    }

    # Определить категорию и ключ дедупликации
    is_delegation = event.startswith("task.delegation")
    if is_delegation:
        category = "delegations"
        dedup_key = payload.get("delegation_id", task_id)
    elif event == "task.pending_review":
        category = "reviews"
        dedup_key = task_id
    else:
        category = "tasks"
        dedup_key = task_id

    for user_id in user_ids:
        chat_id = user_to_chat.get(user_id)
        if chat_id is None:
            continue

        if await is_notified(chat_id, category, dedup_key):
            continue

        text = _format_message(event, task, payload)
        if not text:
            continue

        try:
            kwargs: dict = {}
            if event == "task.assigned":
                kwargs["reply_markup"] = keyboards.task_actions(
                    task_id, task.get("response_type", ""), "pending"
                )
            elif event == "task.pending_review":
                response_id = payload.get("response_id")
                if response_id:
                    kwargs["reply_markup"] = keyboards.review_actions(response_id)
            elif event == "task.delegation_requested":
                delegation_id = payload.get("delegation_id")
                if delegation_id:
                    kwargs["reply_markup"] = keyboards.delegation_incoming_actions(
                        delegation_id
                    )
            await bot.send_message(chat_id, text, **kwargs)
            await add_notified(chat_id, category, dedup_key)
        except Exception:
            logger.warning("Не удалось отправить уведомление chat_id=%s", chat_id)


def _format_message(event: str, task: dict, payload: dict) -> str | None:
    """Сформировать текст уведомления по типу события."""
    if event == "task.assigned":
        return messages.notification_new_task(task)
    if event == "task.pending_review":
        return messages.notification_pending_review(
            task, payload.get("submitted_by", "")
        )
    if event == "task.approved":
        return messages.notification_approved(task)
    if event == "task.rejected":
        return messages.notification_rejected(task, payload.get("reason", ""))
    if event == "task.delegation_requested":
        return messages.delegation_requested_notification(
            task, payload.get("from_user", ""), payload.get("reason", ""),
        )
    if event == "task.delegation_accepted":
        return messages.delegation_accepted_notification(
            task, payload.get("to_user", ""),
        )
    if event == "task.delegation_rejected":
        return messages.delegation_rejected_notification(
            task, payload.get("to_user", ""), payload.get("reason", ""),
        )
    return None
