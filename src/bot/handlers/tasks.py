"""Обработчики задач: /tasks, /task, inline actions, загрузка доказательств."""

from __future__ import annotations

import asyncio
import logging
from typing import Any

import httpx
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import (
    CallbackQuery,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Message,
)

from ...api.client import TaskMateAPI
from ...storage.sessions import UserSession
from .. import keyboards, messages
from ...utils.tz_utils import attach_dealership_timezone

logger = logging.getLogger(__name__)
router = Router()

MAX_PROOF_FILES = 5
MAX_PROOF_TOTAL_BYTES = 50 * 1024 * 1024  # 50 MB

# Статусы, при которых делегирование недоступно
_NO_DELEGATION_STATUSES = {"completed", "completed_late", "pending_review"}


async def _build_delegation_kb(
    api: TaskMateAPI,
    task: dict[str, Any],
    session: UserSession,
) -> InlineKeyboardMarkup | None:
    """Построить кнопку делегирования для employee (если доступно)."""
    if session.role != "employee":
        return None

    status = task.get("status", "")
    if status in _NO_DELEGATION_STATUSES:
        return None

    # Проверить, назначен ли пользователь
    assigned = False
    for a in task.get("assignments", []):
        uid = a.get("user_id") or a.get("user", {}).get("id")
        if uid == session.user_id:
            assigned = True
            break
    if not assigned:
        return None

    # Проверить, есть ли pending outgoing делегация
    try:
        result = await api.get_delegations(
            {
                "task_id": task["id"],
                "direction": "outgoing",
                "status": "pending",
                "per_page": 1,
            }
        )
        pending = result.get("data", [])
    except Exception:
        return None

    if pending:
        dlg_id = pending[0]["id"]
        return InlineKeyboardMarkup(
            inline_keyboard=[
                [
                    InlineKeyboardButton(
                        text="❌ Отменить делегирование",
                        callback_data=f"dlg_cancel:{dlg_id}",
                    )
                ]
            ]
        )

    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="🔄 Делегировать",
                    callback_data=f"dlg_start:{task['id']}",
                )
            ]
        ]
    )


class ProofUpload(StatesGroup):
    """FSM: загрузка доказательств."""

    collecting = State()


@router.message(Command("tasks"))
async def cmd_tasks(message: Message, session: UserSession, **kwargs) -> None:
    """Список задач за сегодня."""
    kb = kwargs.get("reply_keyboard")
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_tasks({"date_range": "today", "per_page": 20})
    except httpx.HTTPStatusError:
        raise
    except Exception:
        logger.exception("Ошибка при получении задач")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    tasks = result.get("data", [])
    if not tasks:
        await message.answer("📋 У вас нет активных задач.", reply_markup=kb)
        return
    # Ensure tasks have dealership.timezone attached for proper local formatting
    if tasks:
        try:
            await asyncio.gather(*(attach_dealership_timezone(api, t) for t in tasks))
        except Exception:
            logger.debug("Не удалось прикрепить timezone для списка задач")

    await message.answer(f"📋 <b>Задачи на сегодня ({len(tasks)})</b>", reply_markup=kb)
    for t in tasks:
        text = messages.task_list_item_text(t)
        item_kb = keyboards.task_list_item(t["id"])
        await message.answer(text, reply_markup=item_kb)
        await asyncio.sleep(0.05)


@router.message(Command("task"))
async def cmd_task(message: Message, session: UserSession, **kwargs) -> None:
    """Детали задачи по ID."""
    reply_kb = kwargs.get("reply_keyboard")
    args = (message.text or "").split()
    if len(args) < 2:
        await message.answer("Использование: /task <i>ID</i>", reply_markup=reply_kb)
        return

    try:
        task_id = int(args[1])
    except ValueError:
        await message.answer("ID задачи должен быть числом.", reply_markup=reply_kb)
        return

    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_task(task_id)
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 404:
            await message.answer(
                f"Задача #{task_id} не найдена.", reply_markup=reply_kb
            )
            return
        raise
    except Exception:
        logger.exception("Ошибка при получении задачи")
        await message.answer(messages.error_generic(), reply_markup=reply_kb)
        return

    task = result.get("data", result)
    kb = keyboards.task_actions(
        task["id"], task.get("response_type", ""), task.get("status", "")
    )
    try:
        await attach_dealership_timezone(api, task)
    except Exception:
        logger.debug("Не удалось прикрепить timezone для задачи %s", task.get("id"))
    await message.answer(messages.task_detail(task), reply_markup=kb or reply_kb)

    dlg_kb = await _build_delegation_kb(api, task, session)
    if dlg_kb:
        await message.answer("🔄 Делегирование:", reply_markup=dlg_kb)


# --- Callback handlers ---


@router.callback_query(F.data.startswith("task_detail:"))
async def cb_task_detail(callback: CallbackQuery, session: UserSession) -> None:
    """Показать детали задачи по inline-кнопке."""
    task_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_task(task_id)
    except Exception:
        logger.exception("Ошибка при загрузке задачи #%s", task_id)
        await callback.answer("Ошибка загрузки", show_alert=True)
        return

    task = result.get("data", result)
    kb = keyboards.task_actions(
        task["id"], task.get("response_type", ""), task.get("status", "")
    )
    try:
        await attach_dealership_timezone(api, task)
    except Exception:
        logger.debug("Не удалось прикрепить timezone для задачи %s (callback)", task.get("id"))
    await callback.message.answer(messages.task_detail(task), reply_markup=kb)

    dlg_kb = await _build_delegation_kb(api, task, session)
    if dlg_kb:
        await callback.message.answer("🔄 Делегирование:", reply_markup=dlg_kb)

    await callback.answer()


@router.callback_query(F.data.startswith("ack:"))
async def cb_acknowledge(callback: CallbackQuery, session: UserSession) -> None:
    """Ознакомиться с задачей."""
    task_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)
    try:
        await api.update_task_status(task_id, "acknowledged")
    except Exception:
        await callback.answer("Ошибка", show_alert=True)
        return
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer(messages.status_updated(task_id, "acknowledged"))
    await callback.answer("✅")


# --- Complete confirmation flow ---


@router.callback_query(F.data.startswith("complete_confirm:"))
async def cb_complete_confirm(callback: CallbackQuery, session: UserSession) -> None:
    """Показать подтверждение выполнения."""
    task_id = int(callback.data.split(":")[1])
    kb = keyboards.complete_confirmation(task_id)
    await callback.message.answer(
        f"Подтвердите выполнение задачи #{task_id}:",
        reply_markup=kb,
    )
    await callback.answer()


@router.callback_query(F.data.startswith("complete:"))
async def cb_complete(callback: CallbackQuery, session: UserSession) -> None:
    """Выполнить задачу."""
    task_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)
    try:
        await api.update_task_status(task_id, "completed")
    except Exception:
        await callback.answer("Ошибка", show_alert=True)
        return
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer(messages.status_updated(task_id, "completed"))
    await callback.answer("✅")


@router.callback_query(F.data.startswith("complete_cancel:"))
async def cb_complete_cancel(callback: CallbackQuery) -> None:
    """Отменить выполнение."""
    try:
        await callback.message.edit_text("Выполнение отменено.", reply_markup=None)
    except Exception:
        await callback.message.answer("Выполнение отменено.")
    await callback.answer()


# --- Proof upload FSM ---


@router.callback_query(F.data.startswith("proof_start:"))
async def cb_proof_start(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Начать загрузку доказательств."""
    task_id = int(callback.data.split(":")[1])
    await state.set_state(ProofUpload.collecting)
    await state.update_data(task_id=task_id, files=[], total_bytes=0)
    kb = keyboards.proof_actions(task_id)
    await callback.message.answer(messages.proof_upload_prompt(), reply_markup=kb)
    await callback.answer()


@router.message(ProofUpload.collecting, F.photo)
async def on_proof_photo(message: Message, state: FSMContext) -> None:
    """Получить фото как доказательство."""
    data = await state.get_data()
    files: list[dict[str, Any]] = data.get("files", [])
    total_bytes: int = data.get("total_bytes", 0)

    if len(files) >= MAX_PROOF_FILES:
        kb = keyboards.proof_actions(data["task_id"])
        await message.answer(
            f"Максимум файлов: {MAX_PROOF_FILES}. Нажмите «📤 Отправить на проверку».",
            reply_markup=kb,
        )
        return

    photo = message.photo[-1]  # наибольший размер
    file = await message.bot.get_file(photo.file_id)
    file_bytes = await message.bot.download_file(file.file_path)
    content = file_bytes.read()

    if total_bytes + len(content) > MAX_PROOF_TOTAL_BYTES:
        kb = keyboards.proof_actions(data["task_id"])
        await message.answer("Превышен лимит размера файлов (50 МБ).", reply_markup=kb)
        return

    files.append(
        {
            "name": f"photo_{len(files) + 1}.jpg",
            "content": content,
            "mime": "image/jpeg",
        }
    )
    await state.update_data(files=files, total_bytes=total_bytes + len(content))

    kb = keyboards.proof_actions(data["task_id"])
    await message.answer(messages.proof_received(len(files)), reply_markup=kb)


@router.message(ProofUpload.collecting, F.document)
async def on_proof_document(message: Message, state: FSMContext) -> None:
    """Получить документ как доказательство."""
    data = await state.get_data()
    files: list[dict[str, Any]] = data.get("files", [])
    total_bytes: int = data.get("total_bytes", 0)

    if len(files) >= MAX_PROOF_FILES:
        kb = keyboards.proof_actions(data["task_id"])
        await message.answer(
            f"Максимум файлов: {MAX_PROOF_FILES}. Нажмите «📤 Отправить на проверку».",
            reply_markup=kb,
        )
        return

    doc = message.document
    file = await message.bot.get_file(doc.file_id)
    file_bytes = await message.bot.download_file(file.file_path)
    content = file_bytes.read()

    if total_bytes + len(content) > MAX_PROOF_TOTAL_BYTES:
        kb = keyboards.proof_actions(data["task_id"])
        await message.answer("Превышен лимит размера файлов (50 МБ).", reply_markup=kb)
        return

    files.append(
        {
            "name": doc.file_name or f"file_{len(files) + 1}",
            "content": content,
            "mime": doc.mime_type or "application/octet-stream",
        }
    )
    await state.update_data(files=files, total_bytes=total_bytes + len(content))

    kb = keyboards.proof_actions(data["task_id"])
    await message.answer(messages.proof_received(len(files)), reply_markup=kb)


@router.message(ProofUpload.collecting, F.video)
async def on_proof_video(message: Message, state: FSMContext) -> None:
    """Получить видео как доказательство."""
    data = await state.get_data()
    files: list[dict[str, Any]] = data.get("files", [])
    total_bytes: int = data.get("total_bytes", 0)

    if len(files) >= MAX_PROOF_FILES:
        kb = keyboards.proof_actions(data["task_id"])
        await message.answer(
            f"Максимум файлов: {MAX_PROOF_FILES}. Нажмите «📤 Отправить на проверку».",
            reply_markup=kb,
        )
        return

    video = message.video
    file = await message.bot.get_file(video.file_id)
    file_bytes = await message.bot.download_file(file.file_path)
    content = file_bytes.read()

    if total_bytes + len(content) > MAX_PROOF_TOTAL_BYTES:
        kb = keyboards.proof_actions(data["task_id"])
        await message.answer("Превышен лимит размера файлов (50 МБ).", reply_markup=kb)
        return

    files.append(
        {
            "name": video.file_name or f"video_{len(files) + 1}.mp4",
            "content": content,
            "mime": video.mime_type or "video/mp4",
        }
    )
    await state.update_data(files=files, total_bytes=total_bytes + len(content))

    kb = keyboards.proof_actions(data["task_id"])
    await message.answer(messages.proof_received(len(files)), reply_markup=kb)


@router.callback_query(F.data.startswith("proof_submit:"))
async def cb_proof_submit(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Отправить доказательства на проверку."""
    data = await state.get_data()
    task_id = data.get("task_id")
    files: list[dict[str, Any]] = data.get("files", [])

    if not files:
        await callback.answer("Нет загруженных файлов", show_alert=True)
        return

    proof_files = [(f["name"], f["content"], f["mime"]) for f in files]

    api = TaskMateAPI(token=session.token)
    try:
        await api.update_task_status(task_id, "pending_review", proof_files=proof_files)
    except Exception:
        logger.exception("Ошибка отправки доказательств")
        await callback.answer("Ошибка отправки", show_alert=True)
        return

    await state.clear()
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer(messages.proof_submitted())
    await callback.answer("📤")


@router.callback_query(F.data.startswith("proof_cancel:"))
async def cb_proof_cancel(callback: CallbackQuery, state: FSMContext) -> None:
    """Отменить загрузку доказательств."""
    await state.clear()
    try:
        await callback.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await callback.message.answer("Загрузка доказательств отменена.")
    await callback.answer()


# --- FSM fallback: неожиданные сообщения ---


@router.message(ProofUpload.collecting)
async def on_proof_unexpected(message: Message, state: FSMContext) -> None:
    """Обработать неожиданное сообщение во время загрузки доказательств."""
    data = await state.get_data()
    kb = keyboards.proof_actions(data["task_id"])
    await message.answer(
        "Отправьте фото, видео или документ. Текстовые сообщения не принимаются.",
        reply_markup=kb,
    )
