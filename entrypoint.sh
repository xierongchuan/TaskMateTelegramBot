#!/bin/bash
# Entrypoint: устанавливает зависимости в .packages если их нет.
set -e

PACKAGES="/app/.packages"

if [ ! -d "$PACKAGES/aiogram" ]; then
    echo "[entrypoint] Устанавливаю зависимости в .packages..."
    pip install --no-cache-dir --target "$PACKAGES" -r /app/requirements.txt
    echo "[entrypoint] Готово."
fi

export PYTHONPATH="$PACKAGES:$PYTHONPATH"

# CMD может быть ["-m", "src.main"] (bot) или ["python", "-m", "src.worker"] (worker)
# Если первый аргумент начинается с "-", добавляем python
if [[ "${1:-}" == -* ]]; then
    exec python "$@"
else
    exec "$@"
fi
