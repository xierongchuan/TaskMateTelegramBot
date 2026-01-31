"""Обработчики задач: /tasks, /task, inline actions, загрузка доказательств."""

from __future__ import annotations

import logging
from typing import Any

from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import CallbackQuery, Message

import httpx

from src.api.client import TaskMateAPI
from src.bot import keyboards, messages
from src.storage.sessions import UserSession

logger = logging.getLogger(__name__)
router = Router()


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
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 401:
            await message.answer(messages.not_authorized(), reply_markup=kb)
        else:
            await message.answer(messages.error_generic(), reply_markup=kb)
        return
    except Exception:
        logger.exception("Ошибка при получении задач")
        await message.answer(messages.error_generic(), reply_markup=kb)
        return

    tasks = result.get("data", [])
    await message.answer(messages.task_list(tasks), reply_markup=kb)


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
            await message.answer(f"Задача #{task_id} не найдена.", reply_markup=reply_kb)
        elif e.response.status_code == 401:
            await message.answer(messages.not_authorized(), reply_markup=reply_kb)
        else:
            await message.answer(messages.error_generic(), reply_markup=reply_kb)
        return
    except Exception:
        logger.exception("Ошибка при получении задачи")
        await message.answer(messages.error_generic(), reply_markup=reply_kb)
        return

    task = result.get("data", {})
    kb = keyboards.task_actions(
        task["id"], task.get("response_type", ""), task.get("status", "")
    )
    await message.answer(messages.task_detail(task), reply_markup=kb or reply_kb)


# --- Callback handlers ---


@router.callback_query(F.data.startswith("task_detail:"))
async def cb_task_detail(callback: CallbackQuery, session: UserSession) -> None:
    """Показать детали задачи по inline-кнопке."""
    task_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_task(task_id)
    except Exception:
        await callback.answer("Ошибка загрузки", show_alert=True)
        return

    task = result.get("data", {})
    kb = keyboards.task_actions(
        task["id"], task.get("response_type", ""), task.get("status", "")
    )
    await callback.message.answer(messages.task_detail(task), reply_markup=kb)
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
    await callback.message.answer(messages.status_updated(task_id, "acknowledged"))
    await callback.answer("✅")


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
    await callback.message.answer(messages.status_updated(task_id, "completed"))
    await callback.answer("✅")


# --- Proof upload FSM ---


@router.callback_query(F.data.startswith("proof_start:"))
async def cb_proof_start(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Начать загрузку доказательств."""
    task_id = int(callback.data.split(":")[1])
    await state.set_state(ProofUpload.collecting)
    await state.update_data(task_id=task_id, files=[])
    kb = keyboards.proof_actions(task_id)
    await callback.message.answer(messages.proof_upload_prompt(), reply_markup=kb)
    await callback.answer()


@router.message(ProofUpload.collecting, F.photo)
async def on_proof_photo(message: Message, state: FSMContext) -> None:
    """Получить фото как доказательство."""
    data = await state.get_data()
    files: list[dict[str, Any]] = data.get("files", [])

    photo = message.photo[-1]  # наибольший размер
    file = await message.bot.get_file(photo.file_id)
    file_bytes = await message.bot.download_file(file.file_path)

    files.append({
        "name": f"photo_{len(files) + 1}.jpg",
        "content": file_bytes.read(),
        "mime": "image/jpeg",
    })
    await state.update_data(files=files)

    kb = keyboards.proof_actions(data["task_id"])
    await message.answer(messages.proof_received(len(files)), reply_markup=kb)


@router.message(ProofUpload.collecting, F.document)
async def on_proof_document(message: Message, state: FSMContext) -> None:
    """Получить документ как доказательство."""
    data = await state.get_data()
    files: list[dict[str, Any]] = data.get("files", [])

    doc = message.document
    file = await message.bot.get_file(doc.file_id)
    file_bytes = await message.bot.download_file(file.file_path)

    files.append({
        "name": doc.file_name or f"file_{len(files) + 1}",
        "content": file_bytes.read(),
        "mime": doc.mime_type or "application/octet-stream",
    })
    await state.update_data(files=files)

    kb = keyboards.proof_actions(data["task_id"])
    await message.answer(messages.proof_received(len(files)), reply_markup=kb)


@router.message(ProofUpload.collecting, F.video)
async def on_proof_video(message: Message, state: FSMContext) -> None:
    """Получить видео как доказательство."""
    data = await state.get_data()
    files: list[dict[str, Any]] = data.get("files", [])

    video = message.video
    file = await message.bot.get_file(video.file_id)
    file_bytes = await message.bot.download_file(file.file_path)

    files.append({
        "name": video.file_name or f"video_{len(files) + 1}.mp4",
        "content": file_bytes.read(),
        "mime": video.mime_type or "video/mp4",
    })
    await state.update_data(files=files)

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
        await api.update_task_status(
            task_id, "pending_review", proof_files=proof_files
        )
    except Exception:
        logger.exception("Ошибка отправки доказательств")
        await callback.answer("Ошибка отправки", show_alert=True)
        return

    await state.clear()
    await callback.message.answer(messages.proof_submitted())
    await callback.answer("📤")


@router.callback_query(F.data.startswith("proof_cancel:"))
async def cb_proof_cancel(callback: CallbackQuery, state: FSMContext) -> None:
    """Отменить загрузку доказательств."""
    await state.clear()
    await callback.message.answer("Загрузка доказательств отменена.")
    await callback.answer()
