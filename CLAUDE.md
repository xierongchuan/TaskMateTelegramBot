# TaskMateTelegramBot — CLAUDE.md

Telegram бот-клиент для TaskMate. Общие правила — в [../CLAUDE.md](../CLAUDE.md).

## Стек

Python 3.12 + aiogram 3.x + httpx + APScheduler 4.x + Valkey (redis.asyncio).

## Архитектура

```
TaskMateServer (Laravel API) ← REST API → TaskMateTelegramBot → Telegram Bot API → Пользователи
```

Бот НЕ имеет прямого доступа к БД. Все данные — через `/api/v1/*`.

## Структура

```
src/
├── main.py              # Точка входа: bot + scheduler
├── config.py            # pydantic-settings
├── api/client.py        # httpx клиент к TaskMateServer
├── bot/
│   ├── bot.py           # Инициализация, AuthMiddleware
│   ├── messages.py      # Шаблоны сообщений (русский)
│   ├── keyboards.py     # Inline-клавиатуры
│   └── handlers/        # common, auth, tasks, shifts
├── scheduler/polling.py # Периодический опрос API для уведомлений
└── storage/sessions.py  # Сессии chat_id ↔ token в Valkey
```

## Conventions

- **Async everywhere** — aiogram, httpx, redis.asyncio. Синхронный код запрещён.
- **Данные только через API** — никакого прямого доступа к БД или файловой системе сервера.
- **FSM** — для многошаговых операций (загрузка proof файлов).
- **Авторизация** — AuthMiddleware проверяет сессию в Valkey перед каждым handler.
- **Язык UI** — русский.

## Команды бота

| Команда | Доступ | Описание |
|---------|--------|----------|
| /start, /help | публичный | Приветствие, справка |
| /login, /logout | публичный / auth | Авторизация |
| /tasks, /task ID | auth | Список / детали задач |
| /shift, /shifts | auth | Текущая / мои смены |

## Запуск

```bash
# Через podman compose (рекомендуется)
podman compose --profile bot up -d --build

# Логи
podman compose logs -f telegram-bot

# Локально (разработка)
cd TaskMateTelegramBot && pip install -r requirements.txt && python -m src.main
```

## Переменные окружения

| Переменная | Описание |
|-----------|----------|
| TELEGRAM_BOT_TOKEN | Токен бота |
| TASKMATE_API_URL | URL API (http://api:8000/api/v1) |
| VALKEY_HOST/PORT/DB | Подключение к Valkey |
| LOG_LEVEL | Уровень логирования |
