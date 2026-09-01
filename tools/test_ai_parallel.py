#!/usr/bin/env python3
"""Behavior/negative tests for tools/ai_parallel.py."""
from __future__ import annotations

import copy
import importlib.util
import json
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("ai_parallel", ROOT / "tools" / "ai_parallel.py")
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


def require(value: bool, message: str) -> None:
    if not value:
        raise AssertionError(message)


def main() -> int:
    control = mod.load(mod.CONTROL)
    registry = mod.load(mod.WORKSTREAMS)

    code, _ = mod.onboarding_check("agent/task-0017-ses")
    require(code == 2, "new agents must not onboard from worker branches")
    code, message = mod.onboarding_check("main")
    require(code == 0 and message.startswith("Open slots:"), "main onboarding should find capacity")

    slots = mod.open_slots(registry)
    require(slots and slots[0]["id"] == "WS-0017-RESEARCH-QA", "onboarding must choose lowest merge-group slot first")

    full = copy.deepcopy(registry)
    for row in mod.rows(full):
        if row.get("role") != "supervisor":
            row["slot_status"] = "occupied"
            row["assigned_agent"] = f"agent-{row['id']}"
    original = mod.load
    try:
        def fake(path):
            if path == mod.CONTROL:
                return control
            if path == mod.WORKSTREAMS:
                return full
            return original(path)
        mod.load = fake
        code, rejection = mod.onboarding_check("main")
        require(code == 3, "full capacity must reject")
        require(rejection == "Go Home Come Back Next Time", "full-capacity rejection must be exact")
    finally:
        mod.load = original

    row = slots[0]
    payload = {
        "pull_request": {
            "draft": False,
            "head": {"ref": row["branch"]},
            "base": {"ref": "main"},
            "body": f"Workstream: {row['id']}\n",
        }
    }
    with tempfile.TemporaryDirectory() as tmp:
        event = Path(tmp) / "event.json"
        event.write_text(json.dumps(payload), encoding="utf-8")
        errors = mod.validate_pr_event(event)
        require(any("Work Done and Submitted" in error for error in errors), "missing completion signal must fail")
        payload["pull_request"]["body"] += "Work Done and Submitted\n"
        event.write_text(json.dumps(payload), encoding="utf-8")
        require(not mod.validate_pr_event(event), "exact completion signal must pass")

    require(mod.overlaps("app/Modules/Foo/**", "app/Modules/Foo/Bar/**"), "nested scopes must overlap")
    require(not mod.overlaps("app/Modules/Foo/**", "app/Modules/Bar/**"), "disjoint scopes must not overlap")

    print("ai_parallel Supervisor guard tests: PASS (main-first onboarding, open-slot assignment, full-capacity rejection, completion signal, scope overlap)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
