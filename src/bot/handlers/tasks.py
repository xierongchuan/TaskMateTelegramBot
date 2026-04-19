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

# Temporary storage for file contents (keyed by (chat_id, task_id))
_temp_file_storage: dict[tuple[int, int], list[dict[str, Any]]] = {}

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
    # For managers/owners exclude tasks that are currently "на проверке"
    # (status == "pending_review") so they don't see review-only items
    if getattr(session, "role", None) in ("manager", "owner") and tasks:
        original_count = len(tasks)
        tasks = [t for t in tasks if t.get("status") != "pending_review"]
        logger.info(
            "cmd_tasks: role=%s — filtered out %d tasks with status=pending_review",
            session.role,
            original_count - len(tasks),
        )
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
    try:
        logger.info(
            "cmd_task: building keyboard for task=%s user=%s role=%s assignments=%s response_type=%s status=%s",
            task.get("id"),
            session.user_id,
            session.role,
            len(task.get("assignments", [])),
            task.get("response_type"),
            task.get("status"),
        )
    except Exception:
        logger.debug("cmd_task: failed to log task/session info")
    try:
        kb = keyboards.task_actions(task, session)
    except Exception:
        logger.exception("cmd_task: task_actions raised")
        kb = None
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
    try:
        logger.info(
            "cb_task_detail: building keyboard for task=%s user=%s role=%s assignments=%s response_type=%s status=%s",
            task.get("id"),
            getattr(session, "user_id", None),
            getattr(session, "role", None),
            len(task.get("assignments", [])),
            task.get("response_type"),
            task.get("status"),
        )
    except Exception:
        logger.debug("cb_task_detail: failed to log task/session info")
    try:
        kb = keyboards.task_actions(task, session)
    except Exception:
        logger.exception("cb_task_detail: task_actions raised")
        kb = None
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
    chat_id = callback.message.chat.id
    logger.info("cb_proof_start: Setting state to ProofUpload.collecting for task %s", task_id)
    await state.set_state(ProofUpload.collecting)
    await state.update_data(task_id=task_id, files=[], total_bytes=0)
    # Initialize temp storage
    _temp_file_storage[(chat_id, task_id)] = []
    logger.info("cb_proof_start: State and temp storage set successfully")
    kb = keyboards.proof_actions(task_id)
    await callback.message.answer(messages.proof_upload_prompt(), reply_markup=kb)
    await callback.answer()


@router.message(ProofUpload.collecting, F.photo)
async def on_proof_photo(message: Message, state: FSMContext) -> None:
    """Получить фото как доказательство."""
    logger.info("on_proof_photo: Handler triggered for message %s", message.message_id)
    data = await state.get_data()
    task_id = data["task_id"]
    chat_id = message.chat.id
    logger.info("on_proof_photo: State data retrieved: files=%d, total_bytes=%d", len(data.get("files", [])), data.get("total_bytes", 0))
    files_meta: list[dict[str, Any]] = data.get("files", [])
    total_bytes: int = data.get("total_bytes", 0)

    if len(files_meta) >= MAX_PROOF_FILES:
        kb = keyboards.proof_actions(task_id)
        await message.answer(
            f"Максимум файлов: {MAX_PROOF_FILES}. Нажмите «📤 Отправить на проверку».",
            reply_markup=kb,
        )
        return

    photo = message.photo[-1]  # наибольший размер
    logger.info("on_proof_photo: Starting file download for photo %s", photo.file_id)
    try:
        logger.info("on_proof_photo: Calling get_file")
        file = await message.bot.get_file(photo.file_id)
        logger.info("on_proof_photo: get_file completed, file_path=%s", file.file_path)
        logger.info("on_proof_photo: Calling download_file")
        file_bytes = await message.bot.download_file(file.file_path)
        logger.info("on_proof_photo: download_file completed, reading content")
        content = file_bytes.read()
        logger.info("on_proof_photo: Content read, size=%d bytes", len(content))
    except Exception as e:
        logger.exception("Ошибка загрузки фото")
        kb = keyboards.proof_actions(task_id)
        await message.answer("❌ Ошибка загрузки фото. Попробуйте ещё раз.", reply_markup=kb)
        return

    if total_bytes + len(content) > MAX_PROOF_TOTAL_BYTES:
        kb = keyboards.proof_actions(task_id)
        await message.answer("Превышен лимит размера файлов (50 МБ).", reply_markup=kb)
        return

    # Store full file in temp storage
    file_dict = {
        "name": f"photo_{len(files_meta) + 1}.jpg",
        "content": content,
        "mime": "image/jpeg",
    }
    _temp_file_storage[(chat_id, task_id)].append(file_dict)

    # Store metadata in state
    files_meta.append(
        {
            "name": file_dict["name"],
            "size": len(content),
            "mime": "image/jpeg",
        }
    )
    logger.info("on_proof_photo: Appended file to list, now %d files", len(files_meta))
    logger.info("on_proof_photo: Calling state.update_data")
    await state.update_data(files=files_meta, total_bytes=total_bytes + len(content))
    logger.info("on_proof_photo: State updated successfully")

    kb = keyboards.proof_actions(task_id)
    logger.info("on_proof_photo: Sending confirmation message")
    await message.answer(messages.proof_received(len(files_meta)), reply_markup=kb)
    logger.info("on_proof_photo: Handler completed successfully")


@router.message(ProofUpload.collecting, F.document)
async def on_proof_document(message: Message, state: FSMContext) -> None:
    """Получить документ как доказательство."""
    data = await state.get_data()
    task_id = data["task_id"]
    chat_id = message.chat.id
    files_meta: list[dict[str, Any]] = data.get("files", [])
    total_bytes: int = data.get("total_bytes", 0)

    if len(files_meta) >= MAX_PROOF_FILES:
        kb = keyboards.proof_actions(task_id)
        await message.answer(
            f"Максимум файлов: {MAX_PROOF_FILES}. Нажмите «📤 Отправить на проверку».",
            reply_markup=kb,
        )
        return

    doc = message.document
    try:
        file = await message.bot.get_file(doc.file_id)
        file_bytes = await message.bot.download_file(file.file_path)
        content = file_bytes.read()
    except Exception as e:
        logger.exception("Ошибка загрузки документа")
        kb = keyboards.proof_actions(task_id)
        await message.answer("❌ Ошибка загрузки документа. Попробуйте ещё раз.", reply_markup=kb)
        return

    if total_bytes + len(content) > MAX_PROOF_TOTAL_BYTES:
        kb = keyboards.proof_actions(task_id)
        await message.answer("Превышен лимит размера файлов (50 МБ).", reply_markup=kb)
        return

    # Store full file in temp storage
    file_dict = {
        "name": doc.file_name or f"file_{len(files_meta) + 1}",
        "content": content,
        "mime": doc.mime_type or "application/octet-stream",
    }
    _temp_file_storage[(chat_id, task_id)].append(file_dict)

    # Store metadata in state
    files_meta.append(
        {
            "name": file_dict["name"],
            "size": len(content),
            "mime": file_dict["mime"],
        }
    )
    await state.update_data(files=files_meta, total_bytes=total_bytes + len(content))

    kb = keyboards.proof_actions(task_id)
    await message.answer(messages.proof_received(len(files_meta)), reply_markup=kb)


@router.message(ProofUpload.collecting, F.video)
async def on_proof_video(message: Message, state: FSMContext) -> None:
    """Получить видео как доказательство."""
    data = await state.get_data()
    task_id = data["task_id"]
    chat_id = message.chat.id
    files_meta: list[dict[str, Any]] = data.get("files", [])
    total_bytes: int = data.get("total_bytes", 0)

    if len(files_meta) >= MAX_PROOF_FILES:
        kb = keyboards.proof_actions(task_id)
        await message.answer(
            f"Максимум файлов: {MAX_PROOF_FILES}. Нажмите «📤 Отправить на проверку».",
            reply_markup=kb,
        )
        return

    video = message.video
    try:
        file = await message.bot.get_file(video.file_id)
        file_bytes = await message.bot.download_file(file.file_path)
        content = file_bytes.read()
    except Exception as e:
        logger.exception("Ошибка загрузки видео")
        kb = keyboards.proof_actions(task_id)
        await message.answer("❌ Ошибка загрузки видео. Попробуйте ещё раз.", reply_markup=kb)
        return

    if total_bytes + len(content) > MAX_PROOF_TOTAL_BYTES:
        kb = keyboards.proof_actions(task_id)
        await message.answer("Превышен лимит размера файлов (50 МБ).", reply_markup=kb)
        return

    # Store full file in temp storage
    file_dict = {
        "name": video.file_name or f"video_{len(files_meta) + 1}.mp4",
        "content": content,
        "mime": video.mime_type or "video/mp4",
    }
    _temp_file_storage[(chat_id, task_id)].append(file_dict)

    # Store metadata in state
    files_meta.append(
        {
            "name": file_dict["name"],
            "size": len(content),
            "mime": file_dict["mime"],
        }
    )
    await state.update_data(files=files_meta, total_bytes=total_bytes + len(content))

    kb = keyboards.proof_actions(task_id)
    await message.answer(messages.proof_received(len(files_meta)), reply_markup=kb)


@router.callback_query(F.data.startswith("proof_submit:"))
async def cb_proof_submit(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Отправить доказательства на проверку."""
    data = await state.get_data()
    task_id = data.get("task_id")
    chat_id = callback.message.chat.id
    files_meta: list[dict[str, Any]] = data.get("files", [])

    logger.info("cb_proof_submit: Submitting proof for task %s, meta files=%d", task_id, len(files_meta))

    if not files_meta:
        await callback.answer("Нет загруженных файлов", show_alert=True)
        return

    # Get files from temp storage
    files = _temp_file_storage.get((chat_id, task_id), [])
    if not files:
        logger.error("cb_proof_submit: No files in temp storage for chat_id=%s task_id=%s", chat_id, task_id)
        await callback.answer("Ошибка: файлы не найдены", show_alert=True)
        return

    logger.info("cb_proof_submit: Found %d files in temp storage", len(files))
    proof_files = [(f["name"], f["content"], f["mime"]) for f in files]
    logger.info("cb_proof_submit: Prepared proof_files with %d items", len(proof_files))

    api = TaskMateAPI(token=session.token)
    try:
        logger.info("cb_proof_submit: Calling API update_task_status")
        await api.update_task_status(task_id, "pending_review", proof_files=proof_files)
        logger.info("cb_proof_submit: API call successful")
    except Exception:
        logger.exception("Ошибка отправки доказательств")
        await callback.answer("Ошибка отправки", show_alert=True)
        return

    # Clean up temp storage
    _temp_file_storage.pop((chat_id, task_id), None)

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
    data = await state.get_data()
    task_id = data.get("task_id")
    chat_id = callback.message.chat.id

    # Clean up temp storage
    _temp_file_storage.pop((chat_id, task_id), None)

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
    task_id = data["task_id"]
    kb = keyboards.proof_actions(task_id)
    await message.answer(
        "Отправьте фото, видео или документ. Текстовые сообщения не принимаются.",
        reply_markup=kb,
    )
