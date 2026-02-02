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
        "━━━━━━━━━━━━━━━━━━",
        "<b>📋 Задачи</b>",
        f"  Активных: {d.get('active_tasks', 0)}",
        f"  Выполнено: {d.get('completed_tasks', 0)}",
        f"  Просрочено: {d.get('overdue_tasks', 0)}",
        f"  На проверке: {d.get('pending_review_count', 0)}",
        "",
        "<b>🕐 Смены</b>",
        f"  Открытых: {d.get('open_shifts', 0)}",
        f"  Опоздания сегодня: {d.get('late_shifts_today', 0)}",
    ]

    # Генераторы
    if d.get("total_generators") or d.get("tasks_generated_today"):
        lines.append("")
        lines.append("<b>⚙️ Генераторы</b>")
        lines.append(f"  Активных: {d.get('active_generators', 0)} / {d.get('total_generators', 0)}")
        lines.append(f"  Создано задач сегодня: {d.get('tasks_generated_today', 0)}")

    # Просроченные задачи
    overdue = d.get("overdue_tasks_list", [])
    if overdue:
        lines.append("")
        lines.append("━━━━━━━━━━━━━━━━━━")
        lines.append("🔴 <b>Просроченные задачи</b>\n")
        for t in overdue[:5]:
            pri = _priority_icon(t.get("priority", "medium"))
            deadline = _format_deadline(t.get("deadline"))
            lines.append(f"  {pri} <b>#{t['id']}</b> {t.get('title', '')}")
            lines.append(f"     Дедлайн: {deadline}")

    # На проверке
    pending = d.get("pending_review_tasks", [])
    if pending:
        lines.append("")
        lines.append("━━━━━━━━━━━━━━━━━━")
        lines.append("🟡 <b>На проверке</b>\n")
        for t in pending[:5]:
            pri = _priority_icon(t.get("priority", "medium"))
            lines.append(f"  {pri} <b>#{t['id']}</b> {t.get('title', '')}")

    # Активные смены
    shifts = d.get("active_shifts", [])
    if shifts:
        lines.append("")
        lines.append("━━━━━━━━━━━━━━━━━━")
        lines.append("🟢 <b>Активные смены</b>\n")
        for s in shifts[:5]:
            user = s.get("user", {})
            name = user.get("full_name", "—")
            dealer = s.get("dealership", {}).get("name", "")
            status = s.get("status", "")
            icon = {"open": "🟢", "late": "🟡"}.get(status, "⚪")
            line = f"  {icon} {name}"
            if dealer:
                line += f" — {dealer}"
            lines.append(line)

    # Статистика по автосалонам
    dealer_stats = d.get("dealership_shift_stats", [])
    if dealer_stats:
        lines.append("")
        lines.append("━━━━━━━━━━━━━━━━━━")
        lines.append("🏢 <b>Автосалоны</b>\n")
        for ds in dealer_stats[:5]:
            name = ds.get("name", "—")
            total = ds.get("total_shifts", 0)
            on_time = ds.get("on_time", 0)
            late = ds.get("late", 0)
            lines.append(f"  <b>{name}</b>: {total} смен ({on_time} вовремя, {late} опозд.)")

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


def review_task_card(
    t: dict[str, Any],
    responses: list[dict[str, Any]] | None = None,
    response: dict[str, Any] | None = None,
) -> str:
    """Карточка задачи на проверке для менеджера.

    responses — список pending_review responses (для групповых задач).
    response — одиночный response (обратная совместимость / индивидуальный просмотр).
    """
    priority_icon = _priority_icon(t.get("priority", "medium"))
    deadline = _format_deadline(t.get("deadline"))

    # Собираем список pending responses
    pending = responses or ([response] if response else [])

    # Количество файлов
    proofs_count = 0
    if pending:
        first = pending[0]
        proofs_count = len(first.get("proofs", []))
        if not proofs_count and first.get("uses_shared_proofs"):
            proofs_count = len(t.get("shared_proofs", []))

    is_group = len(pending) > 1

    lines = [
        f"🟡 {priority_icon} <b>Задача #{t['id']}</b>",
        f"<b>{t.get('title', '')}</b>",
    ]
    if t.get("description"):
        desc = t["description"]
        if len(desc) > 150:
            desc = desc[:147] + "..."
        lines.append(f"<i>{desc}</i>")

    lines.append("")

    # Все responses задачи (не только pending)
    all_responses = t.get("responses", [])
    rejected = [r for r in all_responses if r.get("status") == "rejected"]
    completed = [r for r in all_responses if r.get("status") == "completed"]

    if is_group:
        lines.append(f"👥 <b>Групповая</b> — на проверке: {len(pending)}")
        for r in pending:
            name = r.get("user", {}).get("full_name", "—")
            lines.append(f"  🟡 {name}")
        if rejected:
            for r in rejected:
                name = r.get("user", {}).get("full_name", "—")
                lines.append(f"  ❌ {name} — отклонено")
        if completed:
            for r in completed:
                name = r.get("user", {}).get("full_name", "—")
                lines.append(f"  ✅ {name} — выполнено")
    else:
        name = "—"
        if pending and pending[0].get("user"):
            name = pending[0]["user"].get("full_name", "—")
        lines.append(f"👤 Исполнитель: {name}")

    lines.append(f"📅 Дедлайн: {deadline}")

    if proofs_count:
        lines.append(f"📎 Файлов: {proofs_count}")

    # Комментарий (для одиночных)
    if not is_group and pending and pending[0].get("comment"):
        lines.append(f"💬 {pending[0]['comment']}")

    return "\n".join(lines)


def review_approved_msg(task_id: int, count: int = 1) -> str:
    if count > 1:
        return f"✅ Задача #{task_id} <b>одобрена</b> для {count} исполнителей."
    return f"✅ Задача #{task_id} <b>одобрена</b>."


def review_rejected_msg(task_id: int, reason: str = "", count: int = 1) -> str:
    if count > 1:
        msg = f"❌ Задача #{task_id} <b>отклонена</b> для {count} исполнителей."
    else:
        msg = f"❌ Задача #{task_id} <b>отклонена</b>."
    if reason:
        msg += f"\nПричина: {reason}"
    return msg


def rejection_reason_prompt() -> str:
    return "✏️ Укажите причину отклонения:"


def notification_pending_review(t: dict[str, Any], submitted_by: str = "") -> str:
    """Уведомление менеджеру о новой задаче на проверку."""
    priority_icon = _priority_icon(t.get("priority", "medium"))
    deadline = _format_deadline(t.get("deadline"))
    lines = [
        f"📋 <b>Новая задача на проверку #{t['id']}</b>",
        "",
        f"{priority_icon} {t.get('title', '')}",
        f"Дедлайн: {deadline}",
    ]
    if submitted_by:
        lines.append(f"Отправил: {submitted_by}")
    return "\n".join(lines)


def shift_card_for_manager(s: dict[str, Any]) -> str:
    """Карточка смены для менеджера."""
    user_name = s.get("user", {}).get("full_name", "—")
    dealership = s.get("dealership", {}).get("name", "—")
    start = _format_datetime(s.get("shift_start"))
    sched_start = _format_datetime(s.get("scheduled_start"))
    sched_end = _format_datetime(s.get("scheduled_end"))
    status = s.get("status", "")
    late_min = s.get("late_minutes", 0)

    status_labels = {
        "open": "🟢 Вовремя",
        "late": f"🟡 Опоздание ({late_min} мин)",
        "closed": "⚪ Закрыта",
        "replaced": "🔄 Замена",
    }
    status_text = status_labels.get(status, status)

    lines = [
        f"<b>{user_name}</b> — {dealership}",
        f"Открыта: {start}",
        f"Расписание: {sched_start} – {sched_end}",
        f"Статус: {status_text}",
    ]
    return "\n".join(lines)


def no_open_shifts() -> str:
    return "ℹ️ Нет открытых смен на сегодня."


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
