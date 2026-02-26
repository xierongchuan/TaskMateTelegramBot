FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

RUN adduser --disabled-password --no-create-home --gecos "" appuser

COPY src/ ./src/

USER appuser

CMD ["python", "-m", "src.main"]
