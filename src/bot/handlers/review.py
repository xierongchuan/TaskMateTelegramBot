"""Обработчики проверки задач: одобрение/отклонение для менеджеров и владельцев."""

from __future__ import annotations

import io
import logging
from typing import Any

from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import BufferedInputFile, CallbackQuery, Message

import httpx

from src.api.client import TaskMateAPI
from src.bot import keyboards, messages
from src.storage.sessions import UserSession

logger = logging.getLogger(__name__)
router = Router()


class RejectReason(StatesGroup):
    """FSM: ожидание причины отклонения."""
    waiting = State()


async def send_review_list(
    message: Message, session: UserSession, reply_kb: Any = None
) -> None:
    """Отправить список задач на проверку — каждая отдельным сообщением."""
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.get_tasks({
            "status": "pending_review",
            "per_page": 20,
        })
    except httpx.HTTPStatusError:
        raise
    except Exception:
        logger.exception("Ошибка при получении задач на проверку")
        await message.answer(messages.error_generic(), reply_markup=reply_kb)
        return

    tasks = result.get("data", [])
    if not tasks:
        await message.answer("✅ Нет задач на проверку.", reply_markup=reply_kb)
        return

    for task in tasks:
        # Найти response со статусом pending_review
        response = _find_pending_review_response(task)
        if not response:
            continue

        response_id = response["id"]
        text = messages.review_task_card(task, response)
        kb = keyboards.review_actions(response_id)

        # Попытаться отправить с фото доказательства
        # Для shared submissions проверяем task.shared_proofs
        photo_sent = False
        is_shared = False
        proofs = response.get("proofs", [])
        if not proofs and response.get("uses_shared_proofs"):
            proofs = task.get("shared_proofs", [])
            is_shared = True
        if proofs:
            first_proof = proofs[0]
            mime = first_proof.get("mime_type", "")
            if mime.startswith("image/") and first_proof.get("url"):
                try:
                    photo_bytes = await api.download_proof_by_url(first_proof["url"])
                    if photo_bytes:
                        photo = BufferedInputFile(
                            photo_bytes,
                            filename=first_proof.get("original_filename", "proof.jpg"),
                        )
                        await message.answer_photo(
                            photo=photo, caption=text, reply_markup=kb
                        )
                        photo_sent = True
                except Exception:
                    logger.debug("Не удалось отправить фото proof %s", first_proof.get("id"))

        if not photo_sent:
            await message.answer(text, reply_markup=kb)


def _find_pending_review_response(task: dict[str, Any]) -> dict[str, Any] | None:
    """Найти первый response со статусом pending_review."""
    for r in task.get("responses", []):
        if r.get("status") == "pending_review":
            return r
    return None


# --- Callback handlers ---


@router.callback_query(F.data.startswith("review_approve:"))
async def cb_review_approve(callback: CallbackQuery, session: UserSession) -> None:
    """Одобрить задачу."""
    response_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)
    try:
        result = await api.approve_response(response_id)
    except httpx.HTTPStatusError as e:
        error_msg = "Ошибка"
        try:
            error_msg = e.response.json().get("message", error_msg)
        except Exception:
            pass
        await callback.answer(error_msg, show_alert=True)
        return
    except Exception:
        await callback.answer("Ошибка при одобрении", show_alert=True)
        return

    task = result.get("data", {})
    task_id = task.get("id", "?")
    text = messages.review_approved_msg(task_id)

    # Обновить сообщение — убрать кнопки
    try:
        if callback.message.photo:
            await callback.message.edit_caption(caption=text, reply_markup=None)
        else:
            await callback.message.edit_text(text, reply_markup=None)
    except Exception:
        await callback.message.answer(text)
    await callback.answer("✅ Одобрено")


@router.callback_query(F.data.startswith("review_reject:"))
async def cb_review_reject(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Начать отклонение — запросить причину."""
    response_id = int(callback.data.split(":")[1])
    await state.set_state(RejectReason.waiting)
    await state.update_data(
        response_id=response_id,
        original_message_id=callback.message.message_id,
        has_photo=bool(callback.message.photo),
    )
    await callback.message.answer(messages.rejection_reason_prompt())
    await callback.answer()


@router.message(RejectReason.waiting, F.text)
async def on_reject_reason(
    message: Message, session: UserSession, state: FSMContext
) -> None:
    """Получить причину отклонения и отправить reject."""
    data = await state.get_data()
    response_id = data["response_id"]
    reason = message.text.strip()

    if not reason:
        await message.answer("Причина не может быть пустой. Попробуйте ещё раз.")
        return

    api = TaskMateAPI(token=session.token)
    try:
        result = await api.reject_response(response_id, reason)
    except httpx.HTTPStatusError as e:
        error_msg = "Ошибка"
        try:
            error_msg = e.response.json().get("message", error_msg)
        except Exception:
            pass
        await message.answer(f"⚠️ {error_msg}")
        await state.clear()
        return
    except Exception:
        logger.exception("Ошибка при отклонении")
        await message.answer(messages.error_generic())
        await state.clear()
        return

    task = result.get("data", {})
    task_id = task.get("id", "?")
    text = messages.review_rejected_msg(task_id, reason)

    # Обновить исходное сообщение
    original_msg_id = data.get("original_message_id")
    has_photo = data.get("has_photo", False)
    if original_msg_id:
        try:
            if has_photo:
                await message.bot.edit_message_caption(
                    chat_id=message.chat.id,
                    message_id=original_msg_id,
                    caption=text,
                    reply_markup=None,
                )
            else:
                await message.bot.edit_message_text(
                    text=text,
                    chat_id=message.chat.id,
                    message_id=original_msg_id,
                    reply_markup=None,
                )
        except Exception:
            pass

    await message.answer(text)
    await state.clear()
