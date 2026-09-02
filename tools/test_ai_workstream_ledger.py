#!/usr/bin/env python3
"""Negative/behavior tests for registered worker continuity-ledger ownership."""
from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("ai_workstream_ledger", ROOT / "tools" / "ai_workstream_ledger.py")
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


def require(value: bool, message: str) -> None:
    if not value:
        raise AssertionError(message)


def fixture_row() -> dict:
    return {
        "id": "WS-FIXTURE",
        "role": "worker",
        "assigned_agent": "agent-fixture",
        "branch": "agent/task-fixture",
        "status": "leased",
        "slot_status": "occupied",
        "write_paths": [
            "app/Modules/Providers/Infrastructure/Connectors/Fixture/**",
            "tests/Feature/Providers/Connectors/Fixture/**",
        ],
    }


def fixture_lease() -> dict:
    return {
        "agent": "agent-fixture",
        "workstream": "WS-FIXTURE",
        "branch": "agent/task-fixture",
        "status": "active",
        "write_paths": [
            "app/Modules/Providers/Infrastructure/Connectors/Fixture/**",
            "tests/Feature/Providers/Connectors/Fixture/**",
        ],
    }


def fixture_shared() -> dict:
    return {
        "supervisor_owned_paths": [
            ".ai/state/**",
            ".ai/parallel/**",
            ".github/**",
            "app/Modules/Providers/Domain/Connectors/Contracts/**",
        ]
    }


def validate(row: dict, lease: dict | None, changed: set[str], shared: dict | None = None, parent: str = "TASK-0017", active: str = "TASK-0017") -> list[str]:
    return mod.validate_worker_change_set(
        row,
        lease,
        changed,
        shared or fixture_shared(),
        parent_task=parent,
        canonical_active_task=active,
    )


def main() -> int:
    row, lease = fixture_row(), fixture_lease()

    allowed = {
        "app/Modules/Providers/Infrastructure/Connectors/Fixture/Connector.php",
        "tests/Feature/Providers/Connectors/Fixture/ConnectorTest.php",
    }
    require(validate(row, lease, allowed) == [], "lease-contained worker product changes must be eligible for deferred global ledger ownership")

    outside = validate(row, lease, allowed | {"app/Modules/Core/Forbidden.php"})
    require(any("outside declared workstream scope" in error for error in outside), "out-of-scope worker writes must fail closed")

    shared = validate(row, lease, allowed | {".ai/state/CURRENT-STATE.yaml"})
    require(any("Supervisor-owned path" in error for error in shared), "worker writes to Supervisor-owned global state must fail closed")

    require(any("no unique active lease" in error for error in validate(row, None, allowed)), "unleased workers must not receive ledger deferral")

    mismatched_lease = fixture_lease()
    mismatched_lease["write_paths"] = ["app/Modules/Providers/Infrastructure/Connectors/Other/**"]
    require(any("lease write paths do not exactly match" in error for error in validate(row, mismatched_lease, allowed)), "lease scope drift must fail closed")

    wrong_parent = validate(row, lease, allowed, parent="TASK-0018", active="TASK-0017")
    require(any("does not match canonical active task" in error for error in wrong_parent), "wrong parent task must fail closed")

    supervisor = fixture_row()
    supervisor["role"] = "supervisor"
    require(any("Supervisor workstreams are not eligible" in error for error in validate(supervisor, lease, allowed)), "Supervisor changes must retain normal global ledger requirements")

    planned = fixture_row()
    planned["status"] = "planned"
    require(any("is not writable" in error for error in validate(planned, lease, allowed)), "planned/unleased lifecycle states must not receive deferral")

    require(mod.scope_contains("app/Foo/**", "app/Foo/Bar.php"), "directory wildcard must contain descendants")
    require(not mod.scope_contains("app/Foo/**", "app/Foobar/Bar.php"), "prefix lookalikes must not escape a declared scope")
    require(mod.scope_contains("README.md", "README.md"), "exact path scopes must match exactly")

    print("ai_workstream_ledger guard tests: PASS (leased scope, shared-path denial, lease drift, parent task, Supervisor denial, lifecycle denial)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
