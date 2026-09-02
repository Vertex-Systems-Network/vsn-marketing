#!/usr/bin/env python3
"""Fail-closed continuity-ledger ownership guard for registered worker workstreams.

Registered non-Supervisor workstreams are intentionally forbidden from mutating
Supervisor-owned global continuity state. This guard proves that a worker change
is fully contained inside one currently leased workstream so the global ledger
update may be deferred to Supervisor integration. All other changes keep the
normal ai_state.py ledger requirement.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
PARALLEL = ROOT / ".ai" / "parallel"
WORKSTREAMS = PARALLEL / "WORKSTREAMS.yaml"
LEASES = PARALLEL / "AGENT-LEASES.yaml"
SHARED = PARALLEL / "SHARED-PATHS.yaml"
STATE = ROOT / ".ai" / "state" / "CURRENT-STATE.yaml"
WRITABLE = {"leased", "in_progress", "paused_for_review", "submitted", "approved", "ready_for_merge"}
NOT_APPLICABLE = 2


def load(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise ValueError(f"missing file: {path.relative_to(ROOT)}") from exc
    except json.JSONDecodeError as exc:
        raise ValueError(f"invalid JSON-compatible YAML: {path.relative_to(ROOT)}: {exc}") from exc
    if not isinstance(value, dict):
        raise ValueError(f"expected object: {path.relative_to(ROOT)}")
    return value


def normalize(value: str) -> str:
    return value.strip().replace("\\", "/").lstrip("./").rstrip("/")


def scope_contains(scope: str, path: str) -> bool:
    raw = normalize(scope)
    candidate = normalize(path)
    wildcard = raw.endswith("/**") or raw.endswith("/*")
    if wildcard:
        raw = raw.rsplit("/", 1)[0]
        return candidate == raw or candidate.startswith(raw + "/")
    return candidate == raw


def changed_files(base: str, head: str) -> set[str]:
    proc = subprocess.run(
        ["git", "diff", "--name-only", base, head],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        raise ValueError(proc.stderr.strip() or f"git diff --name-only {base} {head} failed")
    return {normalize(line) for line in proc.stdout.splitlines() if line.strip()}


def active_task(state: dict[str, Any]) -> str:
    return str(state.get("execution", {}).get("active_task", ""))


def registry_rows(registry: dict[str, Any]) -> list[dict[str, Any]]:
    value = registry.get("workstreams", [])
    if not isinstance(value, list) or not all(isinstance(item, dict) for item in value):
        raise ValueError("WORKSTREAMS workstreams must be an array of objects")
    return value


def lease_rows(leases: dict[str, Any]) -> list[dict[str, Any]]:
    value = leases.get("leases", [])
    if not isinstance(value, list) or not all(isinstance(item, dict) for item in value):
        raise ValueError("AGENT-LEASES leases must be an array of objects")
    return value


def matching_lease(row: dict[str, Any], leases: dict[str, Any]) -> dict[str, Any] | None:
    matches = [
        lease for lease in lease_rows(leases)
        if lease.get("workstream") == row.get("id")
        and lease.get("agent") == row.get("assigned_agent")
        and lease.get("branch") == row.get("branch")
        and lease.get("status") == "active"
    ]
    return matches[0] if len(matches) == 1 else None


def validate_worker_change_set(
    row: dict[str, Any],
    lease: dict[str, Any] | None,
    changed: set[str],
    shared: dict[str, Any],
    *,
    parent_task: str,
    canonical_active_task: str,
) -> list[str]:
    errors: list[str] = []
    wid = str(row.get("id", "<unknown>"))

    if row.get("role") == "supervisor":
        errors.append(f"{wid}: Supervisor workstreams are not eligible for worker ledger deferral")
    if row.get("status") not in WRITABLE:
        errors.append(f"{wid}: workstream status {row.get('status')!r} is not writable")
    if row.get("slot_status") != "occupied" or not row.get("assigned_agent"):
        errors.append(f"{wid}: worker ledger deferral requires an occupied assigned slot")
    if parent_task != canonical_active_task or not canonical_active_task:
        errors.append(f"{wid}: parent task {parent_task!r} does not match canonical active task {canonical_active_task!r}")
    if lease is None:
        errors.append(f"{wid}: no unique active lease matches workstream, branch, and assigned agent")

    scopes = row.get("write_paths", [])
    if not isinstance(scopes, list) or not scopes or not all(isinstance(item, str) and item.strip() for item in scopes):
        errors.append(f"{wid}: declared write_paths must be a non-empty string list")
        scopes = []

    if lease is not None:
        lease_scopes = lease.get("write_paths", [])
        if not isinstance(lease_scopes, list) or sorted(str(item) for item in lease_scopes) != sorted(str(item) for item in scopes):
            errors.append(f"{wid}: active lease write paths do not exactly match the workstream registry")

    shared_scopes = shared.get("supervisor_owned_paths", [])
    if not isinstance(shared_scopes, list):
        errors.append("SHARED-PATHS supervisor_owned_paths must be a list")
        shared_scopes = []

    for path in sorted(changed):
        if any(scope_contains(str(scope), path) for scope in shared_scopes):
            errors.append(f"{wid}: changed Supervisor-owned path: {path}")
        if not any(scope_contains(str(scope), path) for scope in scopes):
            errors.append(f"{wid}: changed path outside declared workstream scope: {path}")

    return errors


def row_for_branch(registry: dict[str, Any], branch: str) -> dict[str, Any] | None:
    return next((row for row in registry_rows(registry) if row.get("branch") == branch), None)


def eligible_rows_for_push(registry: dict[str, Any], leases: dict[str, Any], changed: set[str], shared: dict[str, Any], canonical_active_task: str) -> list[dict[str, Any]]:
    parent = str(registry.get("parent_task", ""))
    eligible: list[dict[str, Any]] = []
    for row in registry_rows(registry):
        if row.get("role") == "supervisor":
            continue
        lease = matching_lease(row, leases)
        if not validate_worker_change_set(
            row,
            lease,
            changed,
            shared,
            parent_task=parent,
            canonical_active_task=canonical_active_task,
        ):
            eligible.append(row)
    return eligible


def evaluate_pr(event_path: Path, base: str, head: str) -> tuple[int, str]:
    payload = json.loads(event_path.read_text(encoding="utf-8"))
    pr = payload.get("pull_request")
    if not isinstance(pr, dict):
        return NOT_APPLICABLE, "Not a pull-request event; global ledger rule remains applicable."

    registry, leases, shared, state = load(WORKSTREAMS), load(LEASES), load(SHARED), load(STATE)
    branch = str(pr.get("head", {}).get("ref", ""))
    row = row_for_branch(registry, branch)
    if row is None or row.get("role") == "supervisor":
        return NOT_APPLICABLE, "PR is not a registered non-Supervisor workstream; global ledger rule remains applicable."
    if pr.get("base", {}).get("ref") != "main":
        return 1, f"Registered worker PR {branch} must target main."

    changed = changed_files(base, head)
    errors = validate_worker_change_set(
        row,
        matching_lease(row, leases),
        changed,
        shared,
        parent_task=str(registry.get("parent_task", "")),
        canonical_active_task=active_task(state),
    )
    if errors:
        return 1, "\n".join(errors)
    return 0, f"Worker ledger deferral authorized for {row.get('id')}: all {len(changed)} changed path(s) are lease-contained and Supervisor-owned global state remains untouched."


def evaluate_push(base: str, head: str) -> tuple[int, str]:
    registry, leases, shared, state = load(WORKSTREAMS), load(LEASES), load(SHARED), load(STATE)
    changed = changed_files(base, head)
    eligible = eligible_rows_for_push(registry, leases, changed, shared, active_task(state))
    if len(eligible) == 1:
        row = eligible[0]
        return 0, f"Worker merge ledger deferral authorized for {row.get('id')}: all {len(changed)} changed path(s) are contained by its unique active lease."
    if len(eligible) > 1:
        return 1, "Push change set ambiguously matches more than one active worker lease; refusing ledger deferral."
    return NOT_APPLICABLE, "Push does not exactly match one active worker lease; global ledger rule remains applicable."


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=["pr", "push"], required=True)
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--event-path")
    args = parser.parse_args()
    try:
        if args.mode == "pr":
            if not args.event_path:
                print("ERROR: --event-path is required in PR mode", file=sys.stderr)
                return 1
            code, message = evaluate_pr(Path(args.event_path), args.base, args.head)
        else:
            code, message = evaluate_push(args.base, args.head)
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print(f"ERROR: worker ledger guard failed closed: {exc}", file=sys.stderr)
        return 1

    stream = sys.stderr if code == 1 else sys.stdout
    print(message, file=stream)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
