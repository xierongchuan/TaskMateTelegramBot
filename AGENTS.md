# AGENTS.md — TaskMateTelegramBot

Telegram bot client for TaskMate. General rules in [../AGENTS.md](../AGENTS.md).

## Stack

Python 3.12 · aiogram 3 · httpx · APScheduler 4 · Valkey (redis.asyncio) · RabbitMQ consumer.

## Commands

```bash
# Run bot
podman compose --profile bot up -d --build

# Logs
podman compose logs -f telegram-bot

# Local dev
cd TaskMateTelegramBot && pip install -r requirements.txt && python -m src.main
```

## Key Conventions

- **Async everywhere** — aiogram, httpx, redis.asyncio. Synchronous code is forbidden
- **Data only via API** — No direct DB access. All data through REST API `/api/v1/*`
- **FSM** — For multi-step operations (proof uploads, auth)
- **AuthMiddleware** — Checks Valkey session before every handler
- **Language** — Russian for UI messages

## Structure

```
src/
├── main.py           # Entry point: bot + scheduler + RabbitMQ
├── config.py         # pydantic-settings
├── api/client.py     # httpx client to TaskMateServer
├── bot/              # handlers, keyboards, messages
├── scheduler/        # Periodic API polling
├── storage/          # Valkey sessions
└── consumers/        # RabbitMQ consumers
```

## Forbidden

- Synchronous code (requests, sqlite) — async/httpx only
- Direct DB access — REST API only
- Storing tokens in memory — Valkey with TTL only
- Shell commands — use API
- Blocking operations (sleep) — use asyncio.sleep()
