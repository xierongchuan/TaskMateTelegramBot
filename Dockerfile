FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
COPY src/ ./src/
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN adduser --disabled-password --no-create-home --gecos "" appuser

USER appuser

ENTRYPOINT ["/entrypoint.sh"]
CMD ["-m", "src.main"]
