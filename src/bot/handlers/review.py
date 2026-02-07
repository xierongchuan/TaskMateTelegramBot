"""Обработчики проверки задач: одобрение/отклонение для менеджеров и владельцев."""

from __future__ import annotations

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


# --- Helpers ---


def _find_pending_responses(task: dict[str, Any]) -> list[dict[str, Any]]:
    """Все responses со статусом pending_review."""
    return [r for r in task.get("responses", []) if r.get("status") == "pending_review"]


def _get_first_proof_url(task: dict[str, Any], responses: list[dict[str, Any]]) -> str | None:
    """URL первого image proof (individual или shared)."""
    for r in responses:
        for p in r.get("proofs", []):
            if p.get("mime_type", "").startswith("image/") and p.get("url"):
                return p["url"]
    # Shared proofs
    for p in task.get("shared_proofs", []):
        if p.get("mime_type", "").startswith("image/") and p.get("url"):
            return p["url"]
    return None


async def _send_task_card(
    message: Message,
    api: TaskMateAPI,
    task: dict[str, Any],
    pending: list[dict[str, Any]],
    kb: Any,
) -> None:
    """Отправить карточку задачи с фото (если есть)."""
    text = messages.review_task_card(task, responses=pending)
    photo_url = _get_first_proof_url(task, pending)

    if photo_url:
        try:
            photo_bytes = await api.download_proof_by_url(photo_url)
            if photo_bytes:
                photo = BufferedInputFile(photo_bytes, filename="proof.jpg")
                await message.answer_photo(photo=photo, caption=text, reply_markup=kb)
                return
        except Exception:
            logger.debug("Не удалось отправить фото для задачи %s", task.get("id"))

    await message.answer(text, reply_markup=kb)


async def _edit_result(callback: CallbackQuery, text: str) -> None:
    """Обновить сообщение — убрать кнопки и показать результат."""
    try:
        if callback.message.photo:
            await callback.message.edit_caption(caption=text, reply_markup=None)
        else:
            await callback.message.edit_text(text, reply_markup=None)
    except Exception:
        await callback.message.answer(text)


# --- Main list ---


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

    # Группируем по задачам (не по response)
    for task in tasks:
        pending = _find_pending_responses(task)
        if not pending:
            continue

        is_group = len(pending) > 1
        if is_group:
            kb = keyboards.review_group_actions(task["id"])
        else:
            kb = keyboards.review_actions(pending[0]["id"])

        await _send_task_card(message, api, task, pending, kb)


# --- Single response callbacks ---


@router.callback_query(F.data.startswith("review_approve:"))
async def cb_review_approve(callback: CallbackQuery, session: UserSession) -> None:
    """Одобрить одиночный ответ."""
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
    text = messages.review_approved_msg(task.get("id", "?"))
    await _edit_result(callback, text)
    await callback.answer("✅ Одобрено")


@router.callback_query(F.data.startswith("review_reject:"))
async def cb_review_reject(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Начать отклонение одиночного ответа — запросить причину."""
    response_id = int(callback.data.split(":")[1])
    await state.set_state(RejectReason.waiting)
    await state.update_data(
        mode="single",
        response_id=response_id,
        original_message_id=callback.message.message_id,
        has_photo=bool(callback.message.photo),
    )
    await callback.message.answer(
        messages.rejection_reason_prompt(),
        reply_markup=keyboards.reject_cancel_keyboard(),
    )
    await callback.answer()


# --- Group task callbacks ---


@router.callback_query(F.data.startswith("review_approve_all:"))
async def cb_review_approve_all(callback: CallbackQuery, session: UserSession) -> None:
    """Одобрить все pending_review ответы задачи."""
    task_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)

    try:
        result = await api.get_task(task_id)
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 404:
            await callback.answer("Задача не найдена", show_alert=True)
            return
        raise
    except Exception:
        await callback.answer("Ошибка при загрузке", show_alert=True)
        return

    task = result
    if not task:
        await callback.answer("Задача не найдена", show_alert=True)
        return

    pending = _find_pending_responses(task)
    if not pending:
        await callback.answer("Нет ответов на проверке", show_alert=True)
        return

    # Одобрить каждый response
    approved = 0
    for r in pending:
        try:
            await api.approve_response(r["id"])
            approved += 1
        except Exception:
            logger.debug("Не удалось одобрить response %s", r["id"])

    text = messages.review_approved_msg(task_id, count=approved)
    await _edit_result(callback, text)
    await callback.answer(f"✅ Одобрено: {approved}")


@router.callback_query(F.data.startswith("review_reject_all:"))
async def cb_review_reject_all(
    callback: CallbackQuery, session: UserSession, state: FSMContext
) -> None:
    """Начать отклонение всех ответов — запросить причину."""
    task_id = int(callback.data.split(":")[1])
    await state.set_state(RejectReason.waiting)
    await state.update_data(
        mode="all",
        task_id=task_id,
        original_message_id=callback.message.message_id,
        has_photo=bool(callback.message.photo),
    )
    await callback.message.answer(
        messages.rejection_reason_prompt(),
        reply_markup=keyboards.reject_cancel_keyboard(),
    )
    await callback.answer()


@router.callback_query(F.data.startswith("review_individual:"))
async def cb_review_individual(callback: CallbackQuery, session: UserSession) -> None:
    """Показать каждый response отдельным сообщением с индивидуальными кнопками."""
    task_id = int(callback.data.split(":")[1])
    api = TaskMateAPI(token=session.token)

    try:
        result = await api.get_task(task_id)
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 404:
            await callback.answer("Задача не найдена", show_alert=True)
            return
        raise
    except Exception:
        await callback.answer("Ошибка при загрузке", show_alert=True)
        return

    task = result
    if not task:
        await callback.answer("Задача не найдена", show_alert=True)
        return

    pending = _find_pending_responses(task)
    if not pending:
        await callback.answer("Нет ответов на проверке", show_alert=True)
        return

    # Обновить исходное сообщение — убрать кнопки
    try:
        if callback.message.photo:
            await callback.message.edit_caption(
                caption=callback.message.caption + "\n\n📋 <i>Индивидуальный просмотр:</i>",
                reply_markup=None,
            )
        else:
            await callback.message.edit_text(
                callback.message.text + "\n\n📋 <i>Индивидуальный просмотр:</i>",
                reply_markup=None,
            )
    except Exception:
        pass

    # Отправить каждый response отдельным сообщением
    for r in pending:
        kb = keyboards.review_actions(r["id"])
        await _send_task_card(callback.message, api, task, [r], kb)

    await callback.answer()


# --- Reject cancel callback ---


@router.callback_query(F.data == "reject_cancel")
async def cb_reject_cancel(callback: CallbackQuery, state: FSMContext) -> None:
    """Отменить отклонение."""
    current_state = await state.get_state()
    if current_state == RejectReason.waiting:
        await state.clear()
    try:
        await callback.message.edit_text("Отклонение отменено.", reply_markup=None)
    except Exception:
        await callback.message.answer("Отклонение отменено.")
    await callback.answer()


# --- FSM: получение причины отклонения ---


@router.message(RejectReason.waiting, F.text)
async def on_reject_reason(
    message: Message, session: UserSession, state: FSMContext
) -> None:
    """Получить причину отклонения и отправить reject."""
    data = await state.get_data()
    mode = data.get("mode", "single")
    reason = message.text.strip()

    if not reason:
        await message.answer("Причина не может быть пустой. Попробуйте ещё раз.")
        return

    api = TaskMateAPI(token=session.token)

    if mode == "all":
        task_id = data["task_id"]
        try:
            result = await api.reject_all_responses(task_id, reason)
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
            logger.exception("Ошибка при массовом отклонении")
            await message.answer(messages.error_generic())
            await state.clear()
            return

        task_data = result.get("data", {})
        rejected_count = len([r for r in task_data.get("responses", []) if r.get("status") == "rejected"])
        text = messages.review_rejected_msg(task_id, reason, count=rejected_count)
    else:
        response_id = data["response_id"]
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


# --- FSM fallback: неожиданные сообщения ---


@router.message(RejectReason.waiting)
async def on_reject_unexpected(message: Message, state: FSMContext) -> None:
    """Обработать неожиданное сообщение во время ожидания причины отклонения."""
    await message.answer(
        "Пожалуйста, отправьте текстовое сообщение с причиной отклонения.",
        reply_markup=keyboards.reject_cancel_keyboard(),
    )
