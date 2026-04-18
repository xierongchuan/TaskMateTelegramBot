"""Utilities for timezone parsing, formatting and caching dealership timezones.

Provides:
- `parse_iso_utc` — parse ISO 8601 Z timestamps to aware UTC datetimes
- `to_dealership_tz` — convert UTC datetime to dealership tz (IANA or offset)
- `format_for_user` — Russian formatting `DD.MM.YYYY HH:MM` with optional (UTC)
- `attach_dealership_timezone` — async helper to ensure `obj['dealership']['timezone']` exists (with cache)

Cache TTL: 3600 seconds.
"""

from __future__ import annotations

import re
import time
from datetime import datetime, timedelta, timezone
from typing import Any
from zoneinfo import ZoneInfo

from ..api.client import TaskMateAPI


_TZ_CACHE: dict[int, tuple[str, float]] = {}
_TTL = 3600


def parse_iso_utc(s: str | None) -> datetime | None:
    if not s:
        return None
    try:
        return datetime.fromisoformat(s.replace("Z", "+00:00"))
    except Exception:
        return None


def to_dealership_tz(dt: datetime, tz_name: str | None) -> datetime:
    if dt is None:
        raise ValueError("dt must be a datetime")
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    if tz_name:
        try:
            return dt.astimezone(ZoneInfo(tz_name))
        except Exception:
            # Try parse as offset like +05:00
            m = re.match(r"^([+-])(\d{1,2}):(\d{2})$", tz_name.strip())
            if m:
                sign = 1 if m.group(1) == '+' else -1
                hours = int(m.group(2))
                minutes = int(m.group(3))
                offset = timedelta(hours=hours, minutes=minutes) * sign
                return dt.astimezone(timezone(offset))
    return dt


def format_for_user(iso_or_dt: str | datetime | None, tz_name: str | None) -> str:
    """Format datetime for Russian UI: DD.MM.YYYY HH:MM.

    If `tz_name` is None the string will include " (UTC)" suffix.
    """
    if iso_or_dt is None:
        return "—"
    dt = iso_or_dt
    if isinstance(iso_or_dt, str):
        dt = parse_iso_utc(iso_or_dt)
        if dt is None:
            return iso_or_dt
    local = to_dealership_tz(dt, tz_name)
    if not tz_name:
        return local.strftime("%d.%m.%Y %H:%M") + " (UTC)"
    return local.strftime("%d.%m.%Y %H:%M")


async def attach_dealership_timezone(api: TaskMateAPI, obj: dict[str, Any]) -> None:
    """Ensure `obj['dealership']['timezone']` exists.

    - If object already contains `dealership.timezone` — cache and return.
    - Otherwise attempt to fill from cache by dealership id.
    - If not cached, fetch `/dealerships/{id}` and cache the timezone.

    Modifies `obj` in-place.
    """
    if not obj:
        return
    d = obj.get("dealership")

    # If dealership already has timezone — cache and return
    if isinstance(d, dict) and d.get("timezone"):
        did = d.get("id")
        if did:
            try:
                _TZ_CACHE[int(did)] = (d["timezone"], time.time() + _TTL)
            except Exception:
                pass
        return

    # Find dealership id from either dealership dict or dealership_id field
    did = None
    if isinstance(d, dict):
        did = d.get("id")
    elif obj.get("dealership_id"):
        did = obj.get("dealership_id")

    if not did:
        return

    # Check cache
    cached = _TZ_CACHE.get(int(did))
    if cached and cached[1] > time.time():
        tz_name = cached[0]
        if isinstance(d, dict):
            d["timezone"] = tz_name
        else:
            obj["dealership"] = {"id": int(did), "timezone": tz_name}
        return

    # Fetch from API
    try:
        res = await api.get_dealership(int(did))
        if isinstance(res, dict):
            data = res.get("data", res)
            tz_name = data.get("timezone")
            if tz_name:
                _TZ_CACHE[int(did)] = (tz_name, time.time() + _TTL)
                if isinstance(d, dict):
                    d["timezone"] = tz_name
                else:
                    obj["dealership"] = {"id": int(did), "timezone": tz_name, "name": data.get("name")}
    except Exception:
        # ignore errors — leave object unchanged
        return
