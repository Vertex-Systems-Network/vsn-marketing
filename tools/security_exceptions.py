#!/usr/bin/env python3
"""Validate TASK-0014 security exception governance.

Security scanners fail closed by default. Any future exception must be explicit,
owned, independently approved, evidence-linked, and time bounded. Scanner-local
ignore files and hidden Composer audit ignores are rejected so suppressions
cannot bypass the canonical exception process.
"""

from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
REGISTRY = ROOT / "security" / "exceptions.json"
REQUIRED_FIELDS = {
    "id",
    "scanner",
    "finding",
    "owner",
    "reason",
    "approved_by",
    "created_at",
    "expires_at",
    "evidence",
}
FORBIDDEN_IGNORE_FILES = (
    ROOT / ".trivyignore",
    ROOT / ".trivyignore.yaml",
    ROOT / ".semgrepignore",
)


def parse_datetime(value: Any, field: str, errors: list[str]) -> datetime | None:
    if not isinstance(value, str) or not value.strip():
        errors.append(f"{field} must be a non-empty ISO-8601 timestamp")
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        errors.append(f"{field} is not valid ISO-8601: {value!r}")
        return None
    if parsed.tzinfo is None:
        errors.append(f"{field} must include a timezone: {value!r}")
        return None
    return parsed.astimezone(timezone.utc)


def validate_registry(now: datetime | None = None) -> list[str]:
    errors: list[str] = []
    now = now or datetime.now(timezone.utc)

    try:
        data = json.loads(REGISTRY.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return [f"cannot read {REGISTRY.relative_to(ROOT)}: {exc}"]

    if data.get("schema_version") != 1:
        errors.append("security/exceptions.json schema_version must equal 1")

    exceptions = data.get("exceptions")
    if not isinstance(exceptions, list):
        return errors + ["security/exceptions.json exceptions must be a list"]

    seen_ids: set[str] = set()
    for index, exception in enumerate(exceptions):
        prefix = f"exceptions[{index}]"
        if not isinstance(exception, dict):
            errors.append(f"{prefix} must be an object")
            continue

        missing = sorted(REQUIRED_FIELDS - exception.keys())
        if missing:
            errors.append(f"{prefix} missing fields: {', '.join(missing)}")
            continue

        exception_id = exception.get("id")
        if not isinstance(exception_id, str) or not exception_id.strip():
            errors.append(f"{prefix}.id must be non-empty")
        elif exception_id in seen_ids:
            errors.append(f"duplicate exception id: {exception_id}")
        else:
            seen_ids.add(exception_id)

        for field in ("scanner", "finding", "owner", "reason", "approved_by", "evidence"):
            value = exception.get(field)
            if not isinstance(value, str) or not value.strip():
                errors.append(f"{prefix}.{field} must be non-empty")

        created_at = parse_datetime(exception.get("created_at"), f"{prefix}.created_at", errors)
        expires_at = parse_datetime(exception.get("expires_at"), f"{prefix}.expires_at", errors)
        if created_at and expires_at and expires_at <= created_at:
            errors.append(f"{prefix}.expires_at must be later than created_at")
        if expires_at and expires_at <= now:
            errors.append(f"{prefix} is expired as of {now.isoformat()}")

    for ignore_file in FORBIDDEN_IGNORE_FILES:
        if ignore_file.exists():
            meaningful = [
                line
                for line in ignore_file.read_text(encoding="utf-8").splitlines()
                if line.strip() and not line.lstrip().startswith("#")
            ]
            if meaningful:
                errors.append(
                    f"{ignore_file.relative_to(ROOT)} contains hidden scanner suppressions; "
                    "register and implement a reviewed security exception instead"
                )

    composer_path = ROOT / "composer.json"
    if composer_path.exists():
        composer = json.loads(composer_path.read_text(encoding="utf-8"))
        audit_config = composer.get("config", {}).get("audit", {})
        if isinstance(audit_config, dict) and audit_config.get("ignore"):
            errors.append("composer.json config.audit.ignore is forbidden; use security/exceptions.json")

    return errors


def main() -> int:
    errors = validate_registry()
    if errors:
        print("Security exception policy FAILED:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1

    data = json.loads(REGISTRY.read_text(encoding="utf-8"))
    print(f"Security exception policy PASS: {len(data['exceptions'])} active exception(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
