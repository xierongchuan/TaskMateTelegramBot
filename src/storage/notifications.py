"""Хранение ID уведомлённых задач в Valkey (Redis) для предотвращения дубликатов."""

from __future__ import annotations

from src.storage.sessions import get_redis

KEY_PREFIX = "tmbot:notified:"
CATEGORIES = ("tasks", "deadlines", "overdue", "reviews", "delegations")


async def is_notified(chat_id: int, category: str, task_id: int) -> bool:
    """Проверить, было ли уже отправлено уведомление."""
    r = await get_redis()
    return bool(await r.sismember(f"{KEY_PREFIX}{chat_id}:{category}", str(task_id)))


async def add_notified(chat_id: int, category: str, task_id: int) -> None:
    """Отметить задачу как уведомлённую."""
    r = await get_redis()
    await r.sadd(f"{KEY_PREFIX}{chat_id}:{category}", str(task_id))


async def bulk_add_notified(chat_id: int, category: str, task_ids: list[int]) -> None:
    """Массово отметить задачи как уведомлённые."""
    if not task_ids:
        return
    r = await get_redis()
    members = [str(tid) for tid in task_ids]
    await r.sadd(f"{KEY_PREFIX}{chat_id}:{category}", *members)


async def clear_notified(chat_id: int) -> None:
    """Очистить все уведомления для chat_id (при logout)."""
    r = await get_redis()
    keys = [f"{KEY_PREFIX}{chat_id}:{cat}" for cat in CATEGORIES]
    await r.delete(*keys)
