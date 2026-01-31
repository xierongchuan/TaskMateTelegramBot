"""Шаблоны сообщений на русском языке."""

from __future__ import annotations

from datetime import datetime
from typing import Any


def welcome() -> str:
    return (
        "👋 <b>TaskMate Bot</b>\n\n"
        "Бот-клиент для системы управления задачами автосалона.\n\n"
        "Для начала работы авторизуйтесь:\n"
        "/login <i>логин</i> <i>пароль</i>\n\n"
        "Список команд: /help"
    )


def help_text() -> str:
    return (
        "📖 <b>Команды</b>\n\n"
        "/login <i>логин</i> <i>пароль</i> — авторизация\n"
        "/logout — выход\n"
        "/tasks — мои задачи\n"
        "/task <i>ID</i> — детали задачи\n"
        "/shift — текущая смена\n"
        "/shifts — мои смены\n"
        "/help — справка"
    )


def login_success(full_name: str, role: str) -> str:
    role_labels = {
        "owner": "Владелец",
        "manager": "Менеджер",
        "observer": "Наблюдатель",
        "employee": "Сотрудник",
    }
    return (
        f"✅ Вы авторизованы как <b>{full_name}</b>\n"
        f"Роль: {role_labels.get(role, role)}"
    )


def login_failed(detail: str = "") -> str:
    msg = "❌ Ошибка авторизации"
    if detail:
        msg += f": {detail}"
    return msg


def login_usage() -> str:
    return (
        "Использование: /login <i>логин</i> <i>пароль</i>\n\n"
        "⚠️ Сообщение с паролем будет удалено автоматически."
    )


def logout_success() -> str:
    return "👋 Вы вышли из системы."


def not_authorized() -> str:
    return "🔒 Вы не авторизованы. Используйте /login для входа."


def task_list(tasks: list[dict[str, Any]]) -> str:
    if not tasks:
        return "📋 У вас нет активных задач."

    lines = ["📋 <b>Ваши задачи</b>\n"]
    for t in tasks:
        status_icon = _status_icon(t.get("status", ""))
        priority_icon = _priority_icon(t.get("priority", "medium"))
        deadline = _format_deadline(t.get("deadline"))
        lines.append(
            f"{status_icon} {priority_icon} <b>#{t['id']}</b> {t['title']}"
            f"\n   Дедлайн: {deadline}"
        )
    return "\n".join(lines)


def task_detail(t: dict[str, Any]) -> str:
    status_icon = _status_icon(t.get("status", ""))
    priority_icon = _priority_icon(t.get("priority", "medium"))
    deadline = _format_deadline(t.get("deadline"))
    response_type = t.get("response_type", "")

    type_labels = {
        "notification": "📢 Уведомление",
        "completion": "✅ На выполнение",
        "completion_with_proof": "📎 С доказательствами",
    }

    lines = [
        f"{status_icon} {priority_icon} <b>Задача #{t['id']}</b>",
        f"<b>{t['title']}</b>",
        "",
    ]
    if t.get("description"):
        lines.append(t["description"])
        lines.append("")
    lines.extend(
        [
            f"Тип: {type_labels.get(response_type, response_type)}",
            f"Статус: {_status_label(t.get('status', ''))}",
            f"Дедлайн: {deadline}",
        ]
    )
    if t.get("comment"):
        lines.append(f"Комментарий: {t['comment']}")
    if t.get("creator"):
        creator = t["creator"]
        lines.append(f"Автор: {creator.get('full_name', '—')}")
    return "\n".join(lines)


def shift_info(s: dict[str, Any]) -> str:
    start = _format_datetime(s.get("shift_start"))
    status_labels = {
        "open": "🟢 Открыта",
        "late": "🟡 Опоздание",
        "closed": "⚪ Закрыта",
        "replaced": "🔄 Замена",
    }
    status = status_labels.get(s.get("status", ""), s.get("status", ""))
    dealership = s.get("dealership", {}).get("name", "—")
    return (
        f"🏢 <b>Смена</b>\n\n"
        f"Статус: {status}\n"
        f"Начало: {start}\n"
        f"Автосалон: {dealership}"
    )


def shift_list(shifts: list[dict[str, Any]]) -> str:
    if not shifts:
        return "📅 У вас нет смен."
    lines = ["📅 <b>Ваши смены</b>\n"]
    for s in shifts[:10]:
        start = _format_datetime(s.get("shift_start"))
        status = s.get("status", "")
        icon = {"open": "🟢", "late": "🟡", "closed": "⚪", "replaced": "🔄"}.get(
            status, "⚪"
        )
        lines.append(f"{icon} {start} — {status}")
    return "\n".join(lines)


def no_current_shift() -> str:
    return "ℹ️ У вас нет открытой смены."


def status_updated(task_id: int, new_status: str) -> str:
    return f"✅ Статус задачи #{task_id} обновлён: {_status_label(new_status)}"


def proof_upload_prompt() -> str:
    return (
        "📎 Отправьте фото, видео или документы для подтверждения.\n"
        "Когда закончите, нажмите кнопку «📤 Отправить на проверку»."
    )


def proof_received(count: int) -> str:
    return f"📎 Получено файлов: {count}. Отправьте ещё или нажмите «📤 Отправить на проверку»."


def proof_submitted() -> str:
    return "📤 Доказательства отправлены на проверку."


def notification_new_task(t: dict[str, Any]) -> str:
    deadline = _format_deadline(t.get("deadline"))
    priority_icon = _priority_icon(t.get("priority", "medium"))
    return (
        f"🔔 <b>Новая задача #{t['id']}</b>\n\n"
        f"{priority_icon} {t['title']}\n"
        f"Дедлайн: {deadline}"
    )


def notification_deadline_soon(t: dict[str, Any], minutes: int) -> str:
    return (
        f"⏰ <b>Дедлайн через {minutes} мин!</b>\n\n"
        f"Задача #{t['id']}: {t['title']}"
    )


def notification_overdue(t: dict[str, Any]) -> str:
    return f"🚨 <b>Задача #{t['id']} просрочена!</b>\n\n{t['title']}"


def notification_approved(t: dict[str, Any]) -> str:
    return f"✅ Задача #{t['id']} <b>одобрена</b>: {t['title']}"


def notification_rejected(t: dict[str, Any], reason: str = "") -> str:
    msg = f"❌ Задача #{t['id']} <b>отклонена</b>: {t['title']}"
    if reason:
        msg += f"\nПричина: {reason}"
    return msg


def dashboard_summary(d: dict[str, Any]) -> str:
    lines = [
        "📊 <b>Дашборд</b>\n",
        f"Активных задач: {d.get('active_tasks', 0)}",
        f"Выполнено: {d.get('completed_tasks', 0)}",
        f"Просрочено: {d.get('overdue_tasks', 0)}",
        f"На проверке: {d.get('pending_review_count', 0)}",
        f"Открытых смен: {d.get('open_shifts', 0)}",
        f"Опоздания сегодня: {d.get('late_shifts_today', 0)}",
    ]
    return "\n".join(lines)


def pending_review_list(tasks: list[dict[str, Any]]) -> str:
    if not tasks:
        return "✅ Нет задач на проверку."
    lines = ["✅ <b>Задачи на проверку</b>\n"]
    for t in tasks:
        priority_icon = _priority_icon(t.get("priority", "medium"))
        deadline = _format_deadline(t.get("deadline"))
        lines.append(
            f"{priority_icon} <b>#{t['id']}</b> {t['title']}"
            f"\n   Дедлайн: {deadline}"
        )
    return "\n".join(lines)


def overdue_task_list(tasks: list[dict[str, Any]]) -> str:
    if not tasks:
        return "🔴 Нет просроченных задач."
    lines = ["🔴 <b>Просроченные задачи</b>\n"]
    for t in tasks:
        priority_icon = _priority_icon(t.get("priority", "medium"))
        deadline = _format_deadline(t.get("deadline"))
        lines.append(
            f"{priority_icon} <b>#{t['id']}</b> {t['title']}"
            f"\n   Дедлайн: {deadline}"
        )
    return "\n".join(lines)


def error_generic() -> str:
    return "⚠️ Произошла ошибка. Попробуйте позже."


# --- Вспомогательные ---


def _status_icon(status: str) -> str:
    return {
        "pending": "🔵",
        "acknowledged": "👁",
        "pending_review": "🟡",
        "completed": "✅",
        "completed_late": "⚠️",
        "overdue": "🔴",
        "rejected": "❌",
    }.get(status, "⚪")


def _status_label(status: str) -> str:
    return {
        "pending": "Ожидает",
        "acknowledged": "Ознакомлен",
        "pending_review": "На проверке",
        "completed": "Выполнено",
        "completed_late": "Выполнено с опозданием",
        "overdue": "Просрочено",
        "rejected": "Отклонено",
    }.get(status, status)


def _priority_icon(priority: str) -> str:
    return {"low": "🟢", "medium": "🟡", "high": "🔴"}.get(priority, "")


def _format_deadline(deadline: str | None) -> str:
    if not deadline:
        return "—"
    try:
        dt = datetime.fromisoformat(deadline.replace("Z", "+00:00"))
        return dt.strftime("%d.%m.%Y %H:%M")
    except (ValueError, AttributeError):
        return deadline


def _format_datetime(dt_str: str | None) -> str:
    return _format_deadline(dt_str)
