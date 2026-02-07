"""Polling-уведомления: периодическая проверка приближающихся дедлайнов."""

from __future__ import annotations

import logging
from datetime import datetime, timezone

from aiogram import Bot

import httpx

from src.api.client import TaskMateAPI
from src.bot import messages
from src.storage.notifications import add_notified, is_notified
from src.storage.sessions import get_all_sessions

logger = logging.getLogger(__name__)


async def check_deadlines(bot: Bot) -> None:
    """Проверить приближающиеся дедлайны (30 мин)."""
    now = datetime.now(timezone.utc)
    sessions = await get_all_sessions()
    for chat_id, session in sessions.items():
        try:
            api = TaskMateAPI(token=session.token)
            result = await api.get_tasks({"per_page": 50})
            tasks = result.get("data", [])

            for task in tasks:
                task_id = task["id"]
                if await is_notified(chat_id, "deadlines", task_id):
                    continue
                deadline_str = task.get("deadline")
                if not deadline_str:
                    continue
                status = task.get("status", "")
                if status in ("completed", "completed_late"):
                    continue
                try:
                    deadline = datetime.fromisoformat(
                        deadline_str.replace("Z", "+00:00")
                    )
                except ValueError:
                    continue
                diff = (deadline - now).total_seconds()
                if 0 < diff <= 1800:  # 30 минут
                    await add_notified(chat_id, "deadlines", task_id)
                    minutes = int(diff / 60)
                    await bot.send_message(
                        chat_id,
                        messages.notification_deadline_soon(task, minutes),
                    )
                elif diff <= 0 and status in ("pending", "acknowledged"):
                    if not await is_notified(chat_id, "overdue", task_id):
                        await add_notified(chat_id, "overdue", task_id)
                        await bot.send_message(
                            chat_id,
                            messages.notification_overdue(task),
                        )
        except httpx.HTTPStatusError:
            pass
        except Exception:
            logger.exception("Ошибка polling deadlines для %s", chat_id)
