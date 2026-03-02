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
        "/delegations — мои делегирования\n"
        "/shift — текущая смена\n"
        "/shifts — мои смены\n"
        "/help — справка\n\n"
        "<b>Кнопки меню</b>\n\n"
        "📋 <b>Мои задачи / Задачи</b> — список задач на сегодня\n"
        "🕐 <b>Моя смена / Смены</b> — текущая смена или список смен\n"
        "✅ <b>На проверку</b> — задачи, ожидающие проверки (менеджер)\n"
        "🔴 <b>Просрочены</b> — просроченные задачи (менеджер)\n"
        "📊 <b>Дашборд</b> — сводка по задачам и сменам\n"
        "🚪 <b>Выход</b> — выход из системы"
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

    # Показать причину отклонения для rejected задач
    if t.get("status") == "rejected":
        reason = _extract_reject_reason(t)
        if reason:
            lines.append(f"\n❌ <b>Причина отклонения:</b> {reason}")

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


def dashboard_summary(d: dict[str, Any], role: str = "") -> str:
    lines = [
        "📊 <b>Дашборд</b>\n",
        "━━━━━━━━━━━━━━━━━━",
        "<b>📋 Задачи</b>",
        f"  Активных: {d.get('active_tasks', 0)}",
        f"  Выполнено: {d.get('completed_tasks', 0)}",
        f"  Просрочено: {d.get('overdue_tasks', 0)}",
    ]

    # На проверке — только для менеджеров и выше
    if role not in ("employee", "observer"):
        lines.append(f"  На проверке: {d.get('pending_review_count', 0)}")

    lines.extend([
        "",
        "<b>🕐 Смены</b>",
        f"  Открытых: {d.get('open_shifts', 0)}",
        f"  Опоздания сегодня: {d.get('late_shifts_today', 0)}",
    ])

    # Остальные секции — только для менеджеров и выше
    if role in ("employee", "observer"):
        return "\n".join(lines)

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


def task_list_item_text(t: dict[str, Any]) -> str:
    """Краткий текст задачи для списка (одно сообщение на задачу)."""
    status_icon = _status_icon(t.get("status", ""))
    priority_icon = _priority_icon(t.get("priority", "medium"))
    deadline = _format_deadline(t.get("deadline"))
    return (
        f"{status_icon} {priority_icon} <b>#{t['id']}</b> {t['title']}\n"
        f"Дедлайн: {deadline}"
    )


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


# --- Смены: открытие/закрытие ---


def no_current_shift_with_action() -> str:
    return "ℹ️ У вас нет открытой смены.\nНажмите кнопку ниже, чтобы открыть смену."


def shift_info_with_action(s: dict[str, Any]) -> str:
    start = _format_datetime(s.get("shift_start"))
    status_labels = {
        "open": "🟢 Открыта",
        "late": "🟡 Опоздание",
        "closed": "⚪ Закрыта",
        "replaced": "🔄 Замена",
    }
    status = status_labels.get(s.get("status", ""), s.get("status", ""))
    dealership = s.get("dealership", {}).get("name", "—")
    late_min = s.get("late_minutes", 0)
    lines = [
        "🏢 <b>Смена</b>\n",
        f"Статус: {status}",
        f"Начало: {start}",
        f"Автосалон: {dealership}",
    ]
    if late_min:
        lines.append(f"Опоздание: {late_min} мин")
    return "\n".join(lines)


def shift_select_dealership() -> str:
    return "🏢 Выберите автосалон для открытия смены:"


def shift_select_schedule() -> str:
    return "Доступно несколько смен. Выберите расписание:"


def shift_open_photo_prompt() -> str:
    return "📸 Отправьте фото для открытия смены."


def shift_close_photo_prompt() -> str:
    return "📸 Отправьте фото закрытия смены или нажмите «Без фото»."


def shift_opened_success(s: dict[str, Any]) -> str:
    start = _format_datetime(s.get("shift_start"))
    status_labels = {
        "open": "🟢 Вовремя",
        "late": "🟡 Опоздание",
    }
    status = status_labels.get(s.get("status", ""), s.get("status", ""))
    dealership = s.get("dealership", {}).get("name", "—")
    late_min = s.get("late_minutes", 0)
    lines = [
        "✅ <b>Смена открыта!</b>\n",
        f"Статус: {status}",
        f"Начало: {start}",
        f"Автосалон: {dealership}",
    ]
    if late_min:
        lines.append(f"Опоздание: {late_min} мин")
    return "\n".join(lines)


def shift_closed_success(s: dict[str, Any]) -> str:
    start = _format_datetime(s.get("shift_start"))
    end = _format_datetime(s.get("shift_end"))
    dealership = s.get("dealership", {}).get("name", "—")
    return (
        "🔒 <b>Смена закрыта</b>\n\n"
        f"Начало: {start}\n"
        f"Конец: {end}\n"
        f"Автосалон: {dealership}"
    )


# --- Делегирование ---


def delegation_requested_notification(
    task: dict[str, Any], from_user: str, reason: str = "",
) -> str:
    """Уведомление: входящий запрос на делегирование (RabbitMQ)."""
    lines = [
        f"🔄 <b>Запрос на делегирование</b>",
        "",
        f"Задача: <b>#{task.get('id', '?')}</b> {task.get('title', '')}",
        f"От: {from_user}",
    ]
    if reason:
        lines.append(f"Причина: {reason}")
    lines.append("\nПримите или отклоните запрос.")
    return "\n".join(lines)


def delegation_accepted_notification(
    task: dict[str, Any], to_user: str,
) -> str:
    """Уведомление: делегирование принято."""
    return (
        f"✅ <b>Делегирование принято</b>\n\n"
        f"Задача: <b>#{task.get('id', '?')}</b> {task.get('title', '')}\n"
        f"Принял: {to_user}"
    )


def delegation_rejected_notification(
    task: dict[str, Any], to_user: str, reason: str = "",
) -> str:
    """Уведомление: делегирование отклонено."""
    msg = (
        f"❌ <b>Делегирование отклонено</b>\n\n"
        f"Задача: <b>#{task.get('id', '?')}</b> {task.get('title', '')}\n"
        f"Отклонил: {to_user}"
    )
    if reason:
        msg += f"\nПричина: {reason}"
    return msg


def delegation_created_success(task_id: int, to_user_name: str) -> str:
    return f"✅ Запрос на делегирование задачи #{task_id} отправлен → <b>{to_user_name}</b>"


def delegation_accept_success(delegation_id: int) -> str:
    return f"✅ Делегирование #{delegation_id} <b>принято</b>."


def delegation_reject_success(delegation_id: int) -> str:
    return f"❌ Делегирование #{delegation_id} <b>отклонено</b>."


def delegation_cancel_success(delegation_id: int) -> str:
    return f"🚫 Делегирование #{delegation_id} <b>отменено</b>."


def delegation_reject_reason_prompt() -> str:
    return "✏️ Укажите причину отклонения делегирования:"


def delegation_select_user_prompt(task_id: int) -> str:
    return f"👤 Выберите сотрудника для делегирования задачи #{task_id}:"


def delegation_reason_prompt(task_id: int, to_user_name: str) -> str:
    return (
        f"Делегировать задачу #{task_id} → <b>{to_user_name}</b>\n\n"
        "💬 Укажите причину (или нажмите «Пропустить»):"
    )


def delegation_list(
    delegations: list[dict[str, Any]], direction: str = "",
) -> str:
    """Список делегаций."""
    if not delegations:
        return "🔄 Нет активных делегирований."

    title = "🔄 <b>Делегирования</b>"
    if direction == "incoming":
        title = "📥 <b>Входящие делегирования</b>"
    elif direction == "outgoing":
        title = "📤 <b>Исходящие делегирования</b>"

    lines = [title, ""]
    for d in delegations:
        dlg_id = d.get("id", "?")
        task = d.get("task", {})
        from_u = d.get("from_user", {}).get("full_name", "—")
        to_u = d.get("to_user", {}).get("full_name", "—")
        status = d.get("status", "")
        status_icons = {
            "pending": "🟡",
            "accepted": "✅",
            "rejected": "❌",
            "cancelled": "🚫",
        }
        icon = status_icons.get(status, "⚪")
        lines.append(
            f"{icon} <b>#{dlg_id}</b> Задача #{task.get('id', '?')}: {task.get('title', '')}"
            f"\n   {from_u} → {to_u}"
        )
    return "\n".join(lines)


def no_eligible_users() -> str:
    return "ℹ️ Нет доступных сотрудников для делегирования."


# --- Вспомогательные ---


def _extract_reject_reason(t: dict[str, Any]) -> str:
    """Извлечь последнюю причину отклонения из responses → verification_history."""
    for resp in t.get("responses", []):
        if resp.get("status") != "rejected":
            continue
        for entry in reversed(resp.get("verification_history", [])):
            if entry.get("action") == "rejected" and entry.get("reason"):
                return entry["reason"]
        if resp.get("rejection_reason"):
            return resp["rejection_reason"]
    return ""


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
        return dt.strftime("%d.%m.%Y %H:%M") + " (UTC)"
    except (ValueError, AttributeError):
        return deadline


def _format_datetime(dt_str: str | None) -> str:
    return _format_deadline(dt_str)
