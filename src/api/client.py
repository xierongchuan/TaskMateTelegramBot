"""HTTP-клиент для TaskMateServer API."""

from __future__ import annotations

import io
import logging
from typing import Any

import httpx

from src.config import settings

logger = logging.getLogger(__name__)

REQUEST_TIMEOUT = 30.0


class TaskMateAPI:
    """Async HTTP-клиент к TaskMateServer REST API."""

    def __init__(self, token: str | None = None) -> None:
        self._base_url = settings.taskmate_api_url.rstrip("/")
        self._token = token

    def _headers(self) -> dict[str, str]:
        h: dict[str, str] = {"Accept": "application/json"}
        if self._token:
            h["Authorization"] = f"Bearer {self._token}"
        return h

    async def _request(
        self,
        method: str,
        path: str,
        *,
        json: dict[str, Any] | None = None,
        params: dict[str, Any] | None = None,
        files: list[tuple[str, tuple[str, bytes, str]]] | None = None,
        data: dict[str, Any] | None = None,
    ) -> httpx.Response:
        url = f"{self._base_url}{path}"
        async with httpx.AsyncClient(timeout=REQUEST_TIMEOUT) as client:
            resp = await client.request(
                method,
                url,
                headers=self._headers(),
                json=json,
                params=params,
                files=files,
                data=data,
            )
        return resp

    # --- Аутентификация ---

    async def login(self, login: str, password: str) -> dict[str, Any]:
        """POST /session — авторизация, возвращает token + user."""
        resp = await self._request(
            "POST", "/session", json={"login": login, "password": password}
        )
        resp.raise_for_status()
        return resp.json()

    async def logout(self) -> None:
        """DELETE /session — выход."""
        resp = await self._request("DELETE", "/session")
        resp.raise_for_status()

    async def current_user(self) -> dict[str, Any]:
        """GET /session/current — текущий пользователь."""
        resp = await self._request("GET", "/session/current")
        resp.raise_for_status()
        return resp.json()

    # --- Задачи ---

    async def get_tasks(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /tasks — список задач."""
        resp = await self._request("GET", "/tasks", params=params)
        resp.raise_for_status()
        return resp.json()

    async def get_task(self, task_id: int) -> dict[str, Any]:
        """GET /tasks/{id} — детали задачи."""
        resp = await self._request("GET", f"/tasks/{task_id}")
        resp.raise_for_status()
        return resp.json()

    async def get_my_history(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /tasks/my-history — история моих задач."""
        resp = await self._request("GET", "/tasks/my-history", params=params)
        resp.raise_for_status()
        return resp.json()

    async def update_task_status(
        self,
        task_id: int,
        status: str,
        *,
        complete_for_all: bool = False,
        proof_files: list[tuple[str, bytes, str]] | None = None,
    ) -> dict[str, Any]:
        """PATCH /tasks/{id}/status — обновить статус задачи."""
        if proof_files:
            files = [
                ("proof_files[]", (name, content, mime))
                for name, content, mime in proof_files
            ]
            data = {"status": status}
            if complete_for_all:
                data["complete_for_all"] = "1"
            resp = await self._request(
                "PATCH", f"/tasks/{task_id}/status", files=files, data=data
            )
        else:
            body: dict[str, Any] = {"status": status}
            if complete_for_all:
                body["complete_for_all"] = True
            resp = await self._request(
                "PATCH", f"/tasks/{task_id}/status", json=body
            )
        resp.raise_for_status()
        return resp.json()

    # --- Смены ---

    async def get_my_current_shift(self) -> dict[str, Any]:
        """GET /shifts/my/current — моя текущая смена."""
        resp = await self._request("GET", "/shifts/my/current")
        resp.raise_for_status()
        return resp.json()

    async def get_my_shifts(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /shifts/my — мои смены."""
        resp = await self._request("GET", "/shifts/my", params=params)
        resp.raise_for_status()
        return resp.json()

    # --- Верификация задач (manager/owner) ---

    async def approve_response(self, response_id: int) -> dict[str, Any]:
        """POST /task-responses/{id}/approve — одобрить ответ."""
        resp = await self._request("POST", f"/task-responses/{response_id}/approve")
        resp.raise_for_status()
        return resp.json()

    async def reject_response(
        self, response_id: int, reason: str
    ) -> dict[str, Any]:
        """POST /task-responses/{id}/reject — отклонить ответ."""
        resp = await self._request(
            "POST",
            f"/task-responses/{response_id}/reject",
            json={"reason": reason},
        )
        resp.raise_for_status()
        return resp.json()

    async def reject_all_responses(
        self, task_id: int, reason: str
    ) -> dict[str, Any]:
        """POST /tasks/{id}/reject-all-responses — отклонить все ответы задачи."""
        resp = await self._request(
            "POST",
            f"/tasks/{task_id}/reject-all-responses",
            json={"reason": reason},
        )
        resp.raise_for_status()
        return resp.json()

    # --- Смены (все) ---

    async def get_shifts(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /shifts — список всех смен."""
        resp = await self._request("GET", "/shifts", params=params)
        resp.raise_for_status()
        return resp.json()

    async def get_shift(self, shift_id: int) -> dict[str, Any]:
        """GET /shifts/{id} — детали смены."""
        resp = await self._request("GET", f"/shifts/{shift_id}")
        resp.raise_for_status()
        return resp.json()

    async def download_shift_photo(
        self, shift_id: int, photo_type: str
    ) -> bytes | None:
        """GET /shift-photos/{id}/{type} — скачать фото смены."""
        try:
            resp = await self._request(
                "GET", f"/shift-photos/{shift_id}/{photo_type}"
            )
            if resp.status_code == 200:
                return resp.content
        except Exception:
            logger.debug("Фото смены %s/%s недоступно", shift_id, photo_type)
        return None

    async def download_proof_by_url(self, url: str) -> bytes | None:
        """Скачать файл по signed URL (proof или shared proof)."""
        try:
            async with httpx.AsyncClient(timeout=REQUEST_TIMEOUT) as client:
                resp = await client.get(url, headers=self._headers())
                if resp.status_code == 200:
                    return resp.content
                logger.debug("Proof download failed: %s -> %s", url, resp.status_code)
        except Exception:
            logger.debug("Proof download error: %s", url)
        return None

    # --- Dashboard ---

    async def get_dashboard(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /dashboard — данные дашборда."""
        resp = await self._request("GET", "/dashboard", params=params)
        resp.raise_for_status()
        return resp.json()
