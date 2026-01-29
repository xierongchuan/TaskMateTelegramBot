"""Конфигурация TaskMateBot через переменные окружения."""

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Настройки бота, загружаемые из переменных окружения."""

    telegram_bot_token: str
    taskmate_api_url: str = "http://backend_api:8000/api/v1"

    valkey_host: str = "valkey"
    valkey_port: int = 6379
    valkey_db: int = 1

    log_level: str = "INFO"

    # Интервалы polling (секунды)
    polling_interval_new_tasks: int = 120
    polling_interval_deadlines: int = 300
    polling_interval_overdue: int = 600

    model_config = {"env_file": ".env", "env_file_encoding": "utf-8"}


settings = Settings()
