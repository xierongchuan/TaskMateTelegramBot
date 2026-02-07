"""Хранение сессий telegram_chat_id ↔ token в Valkey (Redis)."""

from __future__ import annotations

import json
import logging
from dataclasses import dataclass

import redis.asyncio as redis

from src.config import settings

logger = logging.getLogger(__name__)

_pool: redis.Redis | None = None

KEY_PREFIX = "tmbot:session:"


@dataclass
class UserSession:
    """Сессия авторизованного пользователя."""

    token: str
    user_id: int
    full_name: str
    role: str
    login: str


async def get_redis() -> redis.Redis:
    """Получить подключение к Valkey."""
    global _pool
    if _pool is None:
        _pool = redis.Redis(
            host=settings.valkey_host,
            port=settings.valkey_port,
            db=settings.valkey_db,
            decode_responses=True,
        )
    return _pool


async def save_session(chat_id: int, session: UserSession) -> None:
    """Сохранить сессию для chat_id."""
    r = await get_redis()
    data = json.dumps(
        {
            "token": session.token,
            "user_id": session.user_id,
            "full_name": session.full_name,
            "role": session.role,
            "login": session.login,
        }
    )
    await r.set(f"{KEY_PREFIX}{chat_id}", data, ex=settings.session_ttl_seconds)


async def get_session(chat_id: int) -> UserSession | None:
    """Получить сессию по chat_id."""
    r = await get_redis()
    data = await r.get(f"{KEY_PREFIX}{chat_id}")
    if data is None:
        return None
    parsed = json.loads(data)
    return UserSession(**parsed)


async def refresh_session_ttl(chat_id: int) -> None:
    """Продлить TTL сессии при активности пользователя."""
    r = await get_redis()
    await r.expire(f"{KEY_PREFIX}{chat_id}", settings.session_ttl_seconds)


async def delete_session(chat_id: int) -> None:
    """Удалить сессию."""
    r = await get_redis()
    await r.delete(f"{KEY_PREFIX}{chat_id}")


async def get_all_sessions() -> dict[int, UserSession]:
    """Получить все активные сессии (для polling уведомлений)."""
    r = await get_redis()
    sessions: dict[int, UserSession] = {}
    async for key in r.scan_iter(match=f"{KEY_PREFIX}*"):
        chat_id_str = key.removeprefix(KEY_PREFIX)
        try:
            chat_id = int(chat_id_str)
        except ValueError:
            continue
        data = await r.get(key)
        if data:
            parsed = json.loads(data)
            sessions[chat_id] = UserSession(**parsed)
    return sessions


async def close() -> None:
    """Закрыть подключение."""
    global _pool
    if _pool is not None:
        await _pool.aclose()
        _pool = None
