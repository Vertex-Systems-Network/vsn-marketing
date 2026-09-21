#!/usr/bin/env python3
"""Validate the durable AI Engineering Supervisor resume contract."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

import runner_benchmark

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / ".ai" / "state" / "CURRENT-STATE.yaml"
CHECKPOINT = ROOT / ".ai" / "state" / "LAST-CHECKPOINT.md"
JOURNAL = ROOT / ".ai" / "state" / "EXECUTION-JOURNAL.jsonl"
QUEUE = ROOT / ".ai" / "coordination" / "OPEN-WORK-QUEUE.yaml"
RUNNER = ROOT / ".ai" / "runner" / "RUNNER-BENCHMARK.yaml"
README = ROOT / "README.md"
README_SYNC_TRIGGER = ".ai/state/CURRENT-STATE.yaml"
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
LIMITS = {STATE: 12 * 1024, CHECKPOINT: 16 * 1024, JOURNAL: 32 * 1024}
MILESTONE_STATUSES = {"READY", "IN_PROGRESS", "VERIFYING", "WAITING_EXTERNAL", "BLOCKED", "COMPLETE"}
SELF_RECONCILIATION_EXACT = {
    ".ai/state/CURRENT-STATE.yaml",
    ".ai/state/LAST-CHECKPOINT.md",
    ".ai/state/EXECUTION-JOURNAL.jsonl",
    ".ai/coordination/OPEN-WORK-QUEUE.yaml",
    ".ai/runner/RUNNER-BENCHMARK.yaml",
    "README.md",
}
SELF_RECONCILIATION_PREFIXES = (".ai/state/archive/",)
REQUIRED_STATE_FIELDS = {
    "observed_main_sha", "active_issue", "active_pr", "active_branch",
    "current_milestone", "milestone_status", "last_completed_milestone",
    "exact_next_safe_action", "pending_runner_ids", "blocked_runner_ids",
    "current_blockers", "timeout_control",
}
MIGRATION_MARKERS = [
    "Migration-Idempotency: reviewed",
    "Migration-Transactions: reviewed",
    "Migration-Apply-Marker-Recovery: reviewed",
    "Migration-Retry: reviewed",
    "Migration-Rollback-Restore: reviewed",
    "Migration-Destructive-Recovery: reviewed",
    "Migration-Concurrency: reviewed",
    "Migration-Partial-Execution: reviewed",
    "Migration-Backup-Snapshot: reviewed",
]


def load(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise ValueError(f"missing required Supervisor file: {path.relative_to(ROOT)}") from exc
    except json.JSONDecodeError as exc:
        raise ValueError(f"invalid JSON-compatible YAML {path.relative_to(ROOT)}: {exc}") from exc
    if not isinstance(value, dict):
        raise ValueError(f"{path.relative_to(ROOT)} must contain an object")
    return value


def standalone(body: str, marker: str) -> bool:
    return any(line.strip() == marker for line in body.splitlines())


def migration_review_errors(changed: set[str], body: str) -> list[str]:
    if not any(path.startswith("database/migrations/") for path in changed):
        return []
    return [f"migration PR missing standalone review marker: {marker}" for marker in MIGRATION_MARKERS if not standalone(body, marker)]


def canonical_percent(value: Any) -> str:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ValueError("canonical progress values must be numeric")
    return f"{float(value):.2f}".rstrip("0").rstrip(".")


def readme_progress_marker(state: dict[str, Any]) -> str:
    progress = state.get("progress")
    execution = state.get("execution")
    if not isinstance(progress, dict) or not isinstance(execution, dict):
        raise ValueError("CURRENT-STATE progress/execution must be objects")
    return (
        "<!-- AI_PROGRESS_SNAPSHOT "
        f"roadmap={canonical_percent(progress.get('roadmap_percent'))} "
        f"phase={canonical_percent(progress.get('phase_percent'))} "
        f"current_phase={execution.get('current_phase')} "
        f"active_task={execution.get('active_task')} "
        f"milestone={state.get('current_milestone')} "
        f"status={state.get('milestone_status')} -->"
    )


def readme_progress_errors(state: dict[str, Any], readme: str) -> list[str]:
    try:
        marker = readme_progress_marker(state)
    except ValueError as exc:
        return [str(exc)]
    if marker not in readme:
        return [f"README progress snapshot is stale; expected exact marker: {marker}"]
    return []


def readme_progress_pr_errors(changed: set[str]) -> list[str]:
    if README_SYNC_TRIGGER in changed and "README.md" not in changed:
        return ["durable milestone state change requires README.md progress synchronization in the same PR"]
    return []


def git_changed_files(base: str, head: str) -> set[str]:
    proc = subprocess.run(
        ["git", "diff", "--name-only", "--diff-filter=ACMRTUXB", base, head],
        cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        raise ValueError(f"git diff failed: {proc.stderr.strip()}")
    return {line.strip() for line in proc.stdout.splitlines() if line.strip()}


def is_self_reconciliation_path(path: str) -> bool:
    value = path.strip().replace("\\", "/")
    while value.startswith("./"):
        value = value[2:]
    return value in SELF_RECONCILIATION_EXACT or any(value.startswith(prefix) for prefix in SELF_RECONCILIATION_PREFIXES)


def main_observation_class(observed: str, current: str, *, ancestor: bool, changed: set[str]) -> str:
    if observed == current:
        return "exact"
    if not ancestor:
        return "conflict"
    if all(is_self_reconciliation_path(path) for path in changed):
        return "self_reconciliation_descendant"
    return "material_drift"


def main_observation_errors(observed: str, current: str, *, ancestor: bool, changed: set[str]) -> list[str]:
    if not SHA_RE.fullmatch(observed or ""):
        return ["observed_main_sha must be an exact 40-char lowercase Git SHA"]
    if not SHA_RE.fullmatch(current or ""):
        return ["current protected-main SHA must be an exact 40-char lowercase Git SHA"]
    classification = main_observation_class(observed, current, ancestor=ancestor, changed=changed)
    if classification in {"exact", "self_reconciliation_descendant"}:
        return []
    if classification == "conflict":
        return [f"protected main {current} is not a descendant of observed snapshot-basis anchor {observed}; reconcile repository truth before writable work"]
    material = sorted(path for path in changed if not is_self_reconciliation_path(path))
    preview = ", ".join(material[:20]) or "<unknown>"
    return [f"material protected-main drift exists since observed snapshot-basis anchor {observed}: {preview}"]


def validate_main_observation(current_main: str) -> tuple[list[str], str]:
    try:
        state = load(STATE)
    except ValueError as exc:
        return [str(exc)], "invalid"
    observed = str(state.get("observed_main_sha", ""))
    verify = subprocess.run(
        ["git", "merge-base", "--is-ancestor", observed, current_main],
        cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    ancestor = verify.returncode == 0
    try:
        changed = set() if observed == current_main else git_changed_files(observed, current_main)
    except ValueError as exc:
        return [str(exc)], "invalid"
    classification = main_observation_class(observed, current_main, ancestor=ancestor, changed=changed)
    return main_observation_errors(observed, current_main, ancestor=ancestor, changed=changed), classification


def validate() -> list[str]:
    errors: list[str] = []
    for path, limit in LIMITS.items():
        if not path.exists():
            errors.append(f"missing compact state file: {path.relative_to(ROOT)}")
        elif path.stat().st_size > limit:
            errors.append(f"{path.relative_to(ROOT)} exceeds compact limit {limit} bytes")

    try:
        state = load(STATE)
        queue = load(QUEUE)
        runner = load(RUNNER)
    except ValueError as exc:
        return errors + [str(exc)]

    missing = sorted(REQUIRED_STATE_FIELDS - set(state))
    if missing:
        errors.append("CURRENT-STATE missing Supervisor fields: " + ", ".join(missing))
    sha = state.get("observed_main_sha")
    if not isinstance(sha, str) or not SHA_RE.fullmatch(sha):
        errors.append("observed_main_sha must be an exact 40-char lowercase Git SHA")
    for field in ("active_issue", "active_pr"):
        value = state.get(field)
        if value is not None and not isinstance(value, int):
            errors.append(f"{field} must be an integer or null")
    if not str(state.get("active_branch", "")).strip():
        errors.append("active_branch is required")
    if state.get("milestone_status") not in MILESTONE_STATUSES:
        errors.append("invalid milestone_status")
    for field in ("current_milestone", "last_completed_milestone", "exact_next_safe_action"):
        if not str(state.get(field, "")).strip():
            errors.append(f"{field} is required")
    for field in ("pending_runner_ids", "blocked_runner_ids", "current_blockers"):
        if not isinstance(state.get(field), list):
            errors.append(f"{field} must be a list")

    if state.get("current_blockers") != state.get("blockers", []):
        errors.append("current_blockers must mirror canonical blockers")
    if state.get("exact_next_safe_action") != state.get("exact_next_action"):
        errors.append("exact_next_safe_action must mirror exact_next_action")

    timeout = state.get("timeout_control")
    expected_timeout = {
        "default_ci_status_refreshes_per_milestone": 1,
        "max_ci_status_refreshes_with_recorded_exception": 2,
        "tight_polling_forbidden": True,
        "pending_ci_state_only_commit_forbidden": True,
    }
    if timeout != expected_timeout:
        errors.append("timeout_control drift")

    if queue.get("schema_version") != 1:
        errors.append("coordination queue schema_version must be 1")
    if queue.get("reconciled_main_sha") != state.get("observed_main_sha"):
        errors.append("coordination queue reconciled_main_sha must match observed_main_sha")
    items = queue.get("items")
    if not isinstance(items, list):
        errors.append("coordination queue items must be a list")
        items = []
    keys: set[tuple[str, int]] = set()
    actionable: list[dict[str, Any]] = []
    for row in items:
        if not isinstance(row, dict):
            errors.append("coordination queue row must be an object")
            continue
        key = (str(row.get("kind")), int(row.get("number", -1)))
        if key in keys:
            errors.append(f"duplicate coordination item {key}")
        keys.add(key)
        if row.get("accepted_actionable") is True:
            actionable.append(row)
    if len(actionable) > 1:
        errors.append("only one accepted actionable work path may be active")
    active_pr = state.get("active_pr")
    if active_pr is not None:
        match = [row for row in actionable if row.get("kind") == "pr" and row.get("number") == active_pr]
        if len(match) != 1:
            errors.append("active_pr must be the accepted actionable coordination item")
        active = queue.get("active_work_path")
        if not isinstance(active, dict) or active.get("kind") != "pr" or active.get("number") != active_pr:
            errors.append("coordination active_work_path must match active_pr")

    runner_errors = runner_benchmark.validate_registry(runner)
    errors.extend(f"Runner Benchmark: {error}" for error in runner_errors)
    runner_ids = {str(row.get("id")) for row in runner.get("tasks", []) if isinstance(row, dict)}
    for field in ("pending_runner_ids", "blocked_runner_ids"):
        for rid in state.get(field, []) if isinstance(state.get(field), list) else []:
            if rid not in runner_ids:
                errors.append(f"{field} references unknown Runner ID {rid}")

    try:
        readme = README.read_text(encoding="utf-8")
        errors.extend(readme_progress_errors(state, readme))
    except OSError as exc:
        errors.append(f"cannot read README progress snapshot: {exc}")

    checkpoint = CHECKPOINT.read_text(encoding="utf-8") if CHECKPOINT.exists() else ""
    for value, label in (
        (state.get("current_milestone"), "current milestone"),
        (state.get("milestone_status"), "milestone status"),
        (state.get("exact_next_safe_action"), "exact next safe action"),
    ):
        if value and str(value) not in checkpoint:
            errors.append(f"LAST-CHECKPOINT does not contain {label}")

    return errors


def validate_pr_event(path: Path, base: str, head: str) -> list[str]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return [f"cannot read PR event: {exc}"]
    pr = payload.get("pull_request")
    if not isinstance(pr, dict):
        return []
    body = pr.get("body") or ""
    try:
        changed = git_changed_files(base, head)
    except ValueError as exc:
        return [str(exc)]
    return migration_review_errors(changed, body) + readme_progress_pr_errors(changed)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    pr = sub.add_parser("validate-pr-event")
    pr.add_argument("--event-path", required=True)
    pr.add_argument("--base", required=True)
    pr.add_argument("--head", required=True)
    main_obs = sub.add_parser("validate-main-observation")
    main_obs.add_argument("--current-main", required=True)
    args = parser.parse_args()
    if args.command == "validate":
        errors = validate()
    elif args.command == "validate-main-observation":
        errors, classification = validate_main_observation(args.current_main)
        if not errors:
            print(f"Protected-main observation: {classification}")
    else:
        errors = validate_pr_event(Path(args.event_path), args.base, args.head)
    if errors:
        print("Supervisor contract validation FAILED:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1
    print("Supervisor contract validation PASSED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
