"""HTTP-клиент для TaskMateServer API."""

from __future__ import annotations

import logging
from typing import Any

import httpx

from src.config import settings

logger = logging.getLogger(__name__)

REQUEST_TIMEOUT = 30.0

# Shared httpx client для переиспользования соединений
_shared_client: httpx.AsyncClient | None = None


async def get_http_client() -> httpx.AsyncClient:
    """Получить shared httpx client с пулом соединений."""
    global _shared_client
    if _shared_client is None or _shared_client.is_closed:
        _shared_client = httpx.AsyncClient(timeout=REQUEST_TIMEOUT)
    return _shared_client


async def close_http_client() -> None:
    """Закрыть shared httpx client."""
    global _shared_client
    if _shared_client is not None:
        await _shared_client.aclose()
        _shared_client = None


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
        raise_for_status: bool = True,
    ) -> httpx.Response:
        url = f"{self._base_url}{path}"
        client = await get_http_client()
        resp = await client.request(
            method,
            url,
            headers=self._headers(),
            json=json,
            params=params,
            files=files,
            data=data,
        )
        if raise_for_status:
            resp.raise_for_status()
        return resp

    # --- Аутентификация ---

    async def login(self, login: str, password: str) -> dict[str, Any]:
        """POST /session — авторизация, возвращает token + user."""
        resp = await self._request(
            "POST", "/session", json={"login": login, "password": password}
        )
        return resp.json()

    async def logout(self) -> None:
        """DELETE /session — выход."""
        await self._request("DELETE", "/session")

    async def current_user(self) -> dict[str, Any]:
        """GET /session/current — текущий пользователь."""
        resp = await self._request("GET", "/session/current")
        return resp.json()

    # --- Задачи ---

    async def get_tasks(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /tasks — список задач."""
        resp = await self._request("GET", "/tasks", params=params)
        return resp.json()

    async def get_task(self, task_id: int) -> dict[str, Any]:
        """GET /tasks/{id} — детали задачи."""
        resp = await self._request("GET", f"/tasks/{task_id}")
        return resp.json()

    async def get_my_history(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /tasks/my-history — история моих задач."""
        resp = await self._request("GET", "/tasks/my-history", params=params)
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
        return resp.json()

    # --- Смены ---

    async def get_my_current_shift(self) -> dict[str, Any]:
        """GET /shifts/my/current — моя текущая смена."""
        resp = await self._request("GET", "/shifts/my/current")
        return resp.json()

    async def get_my_shifts(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /shifts/my — мои смены."""
        resp = await self._request("GET", "/shifts/my", params=params)
        return resp.json()

    async def open_shift(
        self,
        user_id: int,
        dealership_id: int,
        photo: tuple[str, bytes, str],
    ) -> dict[str, Any]:
        """POST /shifts — открыть смену с фото."""
        filename, content, mime = photo
        resp = await self._request(
            "POST",
            "/shifts",
            files=[("opening_photo", (filename, content, mime))],
            data={
                "user_id": str(user_id),
                "dealership_id": str(dealership_id),
            },
        )
        return resp.json()

    async def close_shift(
        self,
        shift_id: int,
        photo: tuple[str, bytes, str] | None = None,
    ) -> dict[str, Any]:
        """PUT /shifts/{id} — закрыть смену (с фото или без)."""
        if photo:
            filename, content, mime = photo
            resp = await self._request(
                "PUT",
                f"/shifts/{shift_id}",
                files=[("closing_photo", (filename, content, mime))],
                data={"status": "closed"},
            )
        else:
            resp = await self._request(
                "PUT",
                f"/shifts/{shift_id}",
                json={"status": "closed"},
            )
        return resp.json()

    async def get_user_dealerships(self) -> list[dict[str, Any]]:
        """Получить список автосалонов текущего пользователя."""
        result = await self.current_user()
        user = result.get("user", result.get("data", result))
        dealerships: list[dict[str, Any]] = []
        # Основной автосалон
        if user.get("dealership"):
            d = user["dealership"]
            dealerships.append({"id": d["id"], "name": d["name"]})
        # Дополнительные автосалоны
        for d in user.get("dealerships", []):
            if not any(existing["id"] == d["id"] for existing in dealerships):
                dealerships.append({"id": d["id"], "name": d["name"]})
        return dealerships

    # --- Верификация задач (manager/owner) ---

    async def approve_response(self, response_id: int) -> dict[str, Any]:
        """POST /task-responses/{id}/approve — одобрить ответ."""
        resp = await self._request("POST", f"/task-responses/{response_id}/approve")
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
        return resp.json()

    # --- Смены (все) ---

    async def get_shifts(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /shifts — список всех смен."""
        resp = await self._request("GET", "/shifts", params=params)
        return resp.json()

    async def get_shift(self, shift_id: int) -> dict[str, Any]:
        """GET /shifts/{id} — детали смены."""
        resp = await self._request("GET", f"/shifts/{shift_id}")
        return resp.json()

    async def download_shift_photo(
        self, shift_id: int, photo_type: str
    ) -> bytes | None:
        """GET /shift-photos/{id}/{type} — скачать фото смены."""
        try:
            resp = await self._request(
                "GET", f"/shift-photos/{shift_id}/{photo_type}",
                raise_for_status=False,
            )
            if resp.status_code == 200:
                return resp.content
        except Exception:
            logger.debug("Фото смены %s/%s недоступно", shift_id, photo_type)
        return None

    async def download_proof_by_url(self, url: str) -> bytes | None:
        """Скачать файл по signed URL (proof или shared proof)."""
        try:
            client = await get_http_client()
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
        return resp.json()

    # --- Делегирование ---

    async def create_delegation(
        self,
        task_id: int,
        to_user_id: int,
        reason: str | None = None,
    ) -> dict[str, Any]:
        """POST /tasks/{id}/delegations — создать запрос на делегирование."""
        body: dict[str, Any] = {"to_user_id": to_user_id}
        if reason:
            body["reason"] = reason
        resp = await self._request(
            "POST", f"/tasks/{task_id}/delegations", json=body
        )
        return resp.json()

    async def get_delegations(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /task-delegations — список делегаций."""
        resp = await self._request("GET", "/task-delegations", params=params)
        return resp.json()

    async def accept_delegation(self, delegation_id: int) -> dict[str, Any]:
        """POST /task-delegations/{id}/accept — принять делегирование."""
        resp = await self._request(
            "POST", f"/task-delegations/{delegation_id}/accept"
        )
        return resp.json()

    async def reject_delegation(
        self, delegation_id: int, reason: str
    ) -> dict[str, Any]:
        """POST /task-delegations/{id}/reject — отклонить делегирование."""
        resp = await self._request(
            "POST",
            f"/task-delegations/{delegation_id}/reject",
            json={"reason": reason},
        )
        return resp.json()

    async def cancel_delegation(self, delegation_id: int) -> dict[str, Any]:
        """POST /task-delegations/{id}/cancel — отменить делегирование."""
        resp = await self._request(
            "POST", f"/task-delegations/{delegation_id}/cancel"
        )
        return resp.json()

    async def get_users(
        self, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET /users — список пользователей."""
        resp = await self._request("GET", "/users", params=params)
        return resp.json()
