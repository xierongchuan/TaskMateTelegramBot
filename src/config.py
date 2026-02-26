"""Конфигурация TaskMateBot через переменные окружения."""

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Настройки бота, загружаемые из переменных окружения."""

    telegram_bot_token: str
    taskmate_api_url: str = "http://backend_api:8000/api/v1"

    valkey_host: str = "valkey"
    valkey_port: int = 6379
    valkey_db: int = 1

    rabbitmq_host: str = "rabbitmq"
    rabbitmq_port: int = 5672
    rabbitmq_user: str = "taskmate"
    rabbitmq_password: str = "taskmate_secret"
    rabbitmq_vhost: str = "/"

    log_level: str = "INFO"

    # Интервал polling дедлайнов (секунды)
    polling_interval_deadlines: int = 300

    # TTL сессий в Valkey (секунды, по умолчанию 7 дней)
    session_ttl_seconds: int = 604800

    model_config = {"env_file": ".env", "env_file_encoding": "utf-8"}


settings = Settings()
