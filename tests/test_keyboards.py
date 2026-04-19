from __future__ import annotations

import os
import sys

# Ensure src is importable for tests
HERE = os.path.dirname(__file__)
SRC = os.path.abspath(os.path.join(HERE, "..", "src"))
if SRC not in sys.path:
    sys.path.insert(0, SRC)

from bot import keyboards
from storage.sessions import UserSession


def make_task(response_type: str = "completion_with_proof", status: str = "pending", assignments=None):
    return {
        "id": 42,
        "response_type": response_type,
        "status": status,
        "assignments": assignments or [],
    }


def test_manager_sees_proof_button():
    task = make_task()
    session = UserSession(token="t", user_id=1, full_name="Mgr", role="manager", login="mgr")
    kb = keyboards.task_actions(task, session)
    assert kb is not None
    assert kb.inline_keyboard[0][0].text == "📎 Загрузить доказательства"


def test_owner_sees_proof_button():
    task = make_task()
    session = UserSession(token="t", user_id=1, full_name="Owner", role="owner", login="owner")
    kb = keyboards.task_actions(task, session)
    assert kb is not None
    assert kb.inline_keyboard[0][0].text == "📎 Загрузить доказательства"


def test_assigned_employee_sees_proof_button():
    task = make_task(assignments=[{"user_id": 5}])
    session = UserSession(token="t", user_id=5, full_name="Emp", role="employee", login="emp")
    kb = keyboards.task_actions(task, session)
    assert kb is not None
    assert kb.inline_keyboard[0][0].text == "📎 Загрузить доказательства"


def test_unassigned_employee_no_button():
    task = make_task(assignments=[{"user_id": 99}])
    session = UserSession(token="t", user_id=5, full_name="Emp", role="employee", login="emp")
    kb = keyboards.task_actions(task, session)
    assert kb is None


def test_manager_sees_proof_on_pending_review():
    task = make_task(status="pending_review")
    session = UserSession(token="t", user_id=1, full_name="Mgr", role="manager", login="mgr")
    kb = keyboards.task_actions(task, session)
    assert kb is not None
    assert kb.inline_keyboard[0][0].text == "📎 Загрузить доказательства"


def test_owner_sees_proof_on_pending_review():
    task = make_task(status="pending_review")
    session = UserSession(token="t", user_id=1, full_name="Owner", role="owner", login="owner")
    kb = keyboards.task_actions(task, session)
    assert kb is not None
    assert kb.inline_keyboard[0][0].text == "📎 Загрузить доказательства"
