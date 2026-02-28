# AGENTS.md

This file provides guidance to agents when working with TaskMateTelegramBot.

## Stack

Python 3.12 + aiogram 3 + RabbitMQ consumer.

## Commands

```bash
# Run bot
podman compose exec telegram-bot python -m src.main

# Run worker
podman compose exec telegram-bot-worker python -m src.worker

# Linting
podman compose exec telegram-bot pip install ruff && ruff check src/
```

## Structure

```
src/
├── main.py       # Bot entry point
├── worker.py     # RabbitMQ consumer
├── config.py     # Configuration
├── api/          # API client
├── bot/          # Bot handlers
├── rabbitmq/     # RabbitMQ utilities
├── scheduler/    # Scheduled tasks
└── storage/      # File storage
```

## Non-Obvious Rules

### API Communication
- Bot communicates with backend API at http://localhost:8007
- All dates in UTC (ISO 8601 with Z suffix)
- Use API client from `src/api/`

### RabbitMQ
- Worker consumes from queues defined in config
- Handles task notifications, proof uploads
- Error handling with retry logic

### File Storage
- Proof files stored via backend API
- Use signed URLs for file access

### Security

- **Input Validation**: Validate all user input from Telegram
- **Command Injection**: Never use `subprocess`, `os.system()`, `eval()`, `exec()` with user data
- **API Security**: Use parameterized queries, validate response data
- **Memory**: Use generators for large datasets, avoid loading all data into memory
- **Secure Storage**: Don't store secrets in code, use environment variables
