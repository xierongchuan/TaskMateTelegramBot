# CLAUDE.md — TaskMateTelegramBot

## О проекте

TaskMateTelegramBot — Telegram бот-клиент для системы TaskMate. Работает через REST API TaskMateServer (`/api/v1/*`), аналогично web-клиенту TaskMateClient.

**Стек:** Python 3.12, aiogram 3.x, httpx, APScheduler 4.x, Valkey (Redis).

## Архитектура

```
TaskMateServer (Laravel API)
       ↕ REST API
TaskMateTelegramBot (Python, aiogram)
       ↕ Telegram Bot API
Пользователи (Telegram)
```

Бот НЕ имеет прямого доступа к БД. Все данные получает через API.

## Структура

```
src/
├── main.py              # Точка входа: bot + scheduler
├── config.py            # Настройки (pydantic-settings)
├── api/client.py        # HTTP-клиент к TaskMateServer API
├── bot/
│   ├── bot.py           # Инициализация aiogram, AuthMiddleware
│   ├── messages.py      # Шаблоны сообщений (русский)
│   ├── keyboards.py     # Inline-клавиатуры
│   └── handlers/
│       ├── common.py    # /start, /help
│       ├── auth.py      # /login, /logout
│       ├── tasks.py     # /tasks, /task, inline actions, FSM загрузки proof
│       └── shifts.py    # /shift, /shifts
├── scheduler/
│   └── polling.py       # Периодический опрос API для уведомлений
└── storage/
    └── sessions.py      # Хранение сессий chat_id ↔ token в Valkey
```

## Команды

```bash
# Запуск (через docker compose)
docker compose --profile bot up -d --build

# Логи
docker compose logs -f telegram_bot

# Без docker (для разработки)
cd TaskMateTelegramBot
pip install -r requirements.txt
python -m src.main
```

## Переменные окружения

| Переменная | Описание | Пример |
|-----------|----------|--------|
| TELEGRAM_BOT_TOKEN | Токен Telegram бота | 123456:ABC... |
| TASKMATE_API_URL | URL API сервера | http://backend_api:8000/api/v1 |
| VALKEY_HOST | Хост Valkey | valkey |
| VALKEY_PORT | Порт Valkey | 6379 |
| VALKEY_DB | Номер БД Valkey | 1 |
| LOG_LEVEL | Уровень логирования | INFO |

## Правила разработки

1. **Язык UI**: русский
2. **Все данные через API**: никакого прямого доступа к БД
3. **Async**: всё асинхронное (aiogram, httpx, redis.asyncio)
4. **Авторизация**: через AuthMiddleware, сессии в Valkey
5. **Docker**: все установки пакетов через Docker
6. **FSM**: для многошаговых операций (загрузка proof файлов)

## Команды бота

| Команда | Доступ | Описание |
|---------|--------|----------|
| /start | публичный | Приветствие |
| /help | публичный | Справка |
| /login | публичный | Авторизация |
| /logout | авторизованный | Выход |
| /tasks | авторизованный | Список задач |
| /task ID | авторизованный | Детали задачи |
| /shift | авторизованный | Текущая смена |
| /shifts | авторизованный | Мои смены |
