# TaskMateTelegramBot — CLAUDE.md

Telegram бот-клиент для TaskMate. Общие правила — в [../CLAUDE.md](../CLAUDE.md).

## Стек

Python 3.12 + aiogram 3.x + httpx + APScheduler 4.x + Valkey (redis.asyncio) + RabbitMQ consumer.

## Архитектура

```
TaskMateServer (Laravel API)
       ↓ REST API
TaskMateTelegramBot (бот)
       ↓ Telegram Bot API
Пользователи (Telegram)

+ RabbitMQ consumer (задачи из backend)
+ APScheduler (периодический опрос уведомлений)
```

Бот НЕ имеет прямого доступа к БД. Все данные — через REST API `/api/v1/*`.

## Структура

```
src/
├── main.py              # Точка входа: bot + scheduler + RabbitMQ consumer
├── config.py            # pydantic-settings (env variables)
├── api/client.py        # httpx клиент к TaskMateServer
├── bot/
│   ├── bot.py           # Инициализация, AuthMiddleware
│   ├── messages.py      # Шаблоны сообщений (русский)
│   ├── keyboards.py     # Inline-клавиатуры
│   └── handlers/        # common, auth, tasks, shifts
├── scheduler/polling.py # Периодический опрос API для уведомлений
├── storage/sessions.py  # Сессии chat_id ↔ token в Valkey
└── consumers/           # RabbitMQ: задачи для пользователей
```

## Конвенции

### Async everywhere

```python
# ПРАВИЛЬНО: async/await везде
async def get_tasks(user_id: int) -> list[Task]:
    tasks = await api_client.get_tasks(user_id)
    return tasks

# НЕПРАВИЛЬНО: синхронный код запрещён
def get_tasks(user_id):
    return requests.get(...).json()
```

### Данные только через API

```python
# ПРАВИЛЬНО: REST API
await api_client.get('/tasks')

# НЕПРАВИЛЬНО: прямой доступ к БД или файловой системе сервера
# (нет доступа, всё идёт через API)
```

### FSM (Finite State Machine)

Для многошаговых операций (загрузка proof файлов, авторизация):

```python
class UploadProofStates(StatesGroup):
    waiting_file = State()
    waiting_comment = State()

# Handler перехватывает состояние и переходит между ними
```

### Авторизация (AuthMiddleware)

```python
# Middleware проверяет сессию в Valkey перед каждым handler
# Токен хранится: Valkey['chat:{chat_id}:token'] = 'Bearer ...'
# Истекает через 24 часа
```

## Команды бота

| Команда | Доступ | Описание |
|---------|--------|----------|
| /start | публичный | Приветствие + ссылка на login |
| /help | публичный | Справка |
| /login | публичный | Авторизация (вводит credentials) |
| /logout | auth | Выход |
| /tasks | auth | Список моих задач |
| /task {id} | auth | Детали задачи |
| /shift | auth | Текущая смена |
| /shifts | auth | Мои смены |

## Переменные окружения

| Переменная | Описание | Пример |
|-----------|----------|---------|
| TELEGRAM_BOT_TOKEN | Токен бота (@BotFather) | `123456:ABCdef...` |
| TASKMATE_API_URL | URL API | `http://api:8000/api/v1` |
| VALKEY_HOST | Хост Redis | `svc-valkey` |
| VALKEY_PORT | Порт Redis | `6379` |
| VALKEY_DB | БД Redis | `0` |
| RABBITMQ_HOST | Хост RabbitMQ | `svc-rabbitmq` |
| RABBITMQ_USER | Пользователь RabbitMQ | `guest` |
| RABBITMQ_PASSWORD | Пароль RabbitMQ | `guest` |
| LOG_LEVEL | Уровень логирования | `INFO` |

## Запуск

```bash
# Через podman compose (рекомендуется)
podman compose --profile bot up -d --build

# Логи
podman compose logs -f telegram-bot

# Локально (разработка)
cd TaskMateTelegramBot && pip install -r requirements.txt && python -m src.main
```

## Запрещено

- Синхронный код (requests, sqlite итд) — только async/httpx
- Прямой доступ к БД — только через REST API
- Хранение sensitive данных (tokens) в памяти — только в Valkey с TTL
- Выполнение shell-команд — используй API для всего
- Блокирующие операции (sleep) — использовать asyncio.sleep()
