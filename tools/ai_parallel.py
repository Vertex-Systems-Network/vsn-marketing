#!/usr/bin/env python3
"""Supervisor-controlled parallel development guard for VSN Marketing."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
PARALLEL = ROOT / ".ai" / "parallel"
CONTROL = PARALLEL / "CONTROL.yaml"
WORKSTREAMS = PARALLEL / "WORKSTREAMS.yaml"
LEASES = PARALLEL / "AGENT-LEASES.yaml"
SHARED = PARALLEL / "SHARED-PATHS.yaml"
PLAN = PARALLEL / "AI-NATIVE-PLAN.md"
STATE = ROOT / ".ai" / "state" / "CURRENT-STATE.yaml"
README = ROOT / "README.md"

VALID_SLOT = {"open", "occupied"}
WRITABLE = {"leased", "in_progress", "paused_for_review", "submitted", "approved", "ready_for_merge"}


def load(path: Path) -> dict[str, Any]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise ValueError(f"missing file: {path.relative_to(ROOT)}") from exc
    except json.JSONDecodeError as exc:
        raise ValueError(f"invalid JSON-compatible YAML: {path.relative_to(ROOT)}: {exc}") from exc
    if not isinstance(data, dict):
        raise ValueError(f"expected object: {path.relative_to(ROOT)}")
    return data


def dump(path: Path, data: dict[str, Any]) -> None:
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def git(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", *args], cwd=ROOT, text=True, capture_output=True, check=check)


def branch_name() -> str:
    result = git("branch", "--show-current", check=False).stdout.strip()
    return result or os.environ.get("GITHUB_HEAD_REF", "")


def sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def instruction_fingerprint(control: dict[str, Any]) -> str:
    sources = control.get("instruction_sources")
    if not isinstance(sources, list) or not sources:
        raise ValueError("CONTROL instruction_sources must be a non-empty list")
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for raw in sources:
        if not isinstance(raw, str) or not raw.strip():
            raise ValueError("instruction source entries must be strings")
        rel = raw.strip().replace("\\", "/")
        if rel in seen:
            raise ValueError(f"duplicate instruction source: {rel}")
        seen.add(rel)
        path = ROOT / rel
        if not path.is_file():
            raise ValueError(f"instruction source missing: {rel}")
        rows.append({"path": rel, "sha256": sha256(path.read_text(encoding="utf-8"))})
    canonical = json.dumps(rows, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return sha256(canonical)


def rows(registry: dict[str, Any]) -> list[dict[str, Any]]:
    value = registry.get("workstreams", [])
    if not isinstance(value, list):
        raise ValueError("WORKSTREAMS workstreams must be a list")
    if not all(isinstance(row, dict) for row in value):
        raise ValueError("WORKSTREAMS entries must be objects")
    return value


def by_id(registry: dict[str, Any]) -> dict[str, dict[str, Any]]:
    return {str(row.get("id")): row for row in rows(registry)}


def by_branch(registry: dict[str, Any]) -> dict[str, dict[str, Any]]:
    return {str(row.get("branch")): row for row in rows(registry)}


def prefix(scope: str) -> str:
    value = scope.strip().replace("\\", "/").lstrip("./")
    for suffix in ("/**", "/*"):
        if value.endswith(suffix):
            value = value[: -len(suffix)]
    return value.rstrip("/")


def overlaps(a: str, b: str) -> bool:
    x, y = prefix(a), prefix(b)
    if not x or not y:
        return True
    return x == y or x.startswith(y + "/") or y.startswith(x + "/")


def standalone(text: str, expected: str) -> bool:
    return any(line.strip() == expected for line in text.splitlines())


def open_slots(registry: dict[str, Any]) -> list[dict[str, Any]]:
    candidates = [
        row for row in rows(registry)
        if row.get("role") != "supervisor"
        and row.get("slot_status") == "open"
        and not row.get("assigned_agent")
    ]
    return sorted(candidates, key=lambda row: (int(row.get("merge_group", 999)), str(row.get("id", ""))))


def current_active_task() -> str:
    state = load(STATE)
    return str(state.get("execution", {}).get("active_task", ""))


def validate() -> list[str]:
    errors: list[str] = []
    try:
        control, registry, leases, shared = load(CONTROL), load(WORKSTREAMS), load(LEASES), load(SHARED)
    except ValueError as exc:
        return [str(exc)]

    for name, doc in (("CONTROL", control), ("WORKSTREAMS", registry), ("AGENT-LEASES", leases), ("SHARED-PATHS", shared)):
        if doc.get("schema_version") != 2:
            errors.append(f"{name} schema_version must be 2")

    if control.get("protected_main_branch") != "main":
        errors.append("protected_main_branch must be main")
    if control.get("required_completion_signal") != "Work Done and Submitted":
        errors.append("completion signal must be exactly Work Done and Submitted")
    if control.get("onboarding_no_slot_message") != "Go Home Come Back Next Time":
        errors.append("onboarding no-slot message must be exactly Go Home Come Back Next Time")
    if not control.get("new_agent_must_start_from_main"):
        errors.append("new_agent_must_start_from_main must be true")
    hard = int(control.get("hard_cap_writers", 0) or 0)
    default = int(control.get("default_max_concurrent_writers", 0) or 0)
    target = int(control.get("scale_target_writers", 0) or 0)
    if not (0 < default <= target <= hard <= 12):
        errors.append("writer capacity must satisfy 0 < default <= target <= hard <= 12")

    try:
        computed = instruction_fingerprint(control)
        configured = str(control.get("instruction_fingerprint", ""))
        if computed != configured:
            errors.append(f"instruction fingerprint drift: expected {configured}, computed {computed}")
        readme = README.read_text(encoding="utf-8")
        revision = str(control.get("instruction_revision", ""))
        if f"Agent instruction revision: `{revision}`" not in readme:
            errors.append("README instruction revision is stale")
        if f"Agent instruction fingerprint: `{configured}`" not in readme:
            errors.append("README instruction fingerprint is stale")
    except (OSError, ValueError) as exc:
        errors.append(str(exc))

    mode = registry.get("mode")
    if mode not in {"staged", "active"}:
        errors.append("WORKSTREAMS mode must be staged or active")
    parent = str(registry.get("parent_task", ""))
    if mode == "active" and parent != current_active_task():
        errors.append(f"active workstreams parent {parent} does not match active task {current_active_task()}")

    ids: set[str] = set()
    branches: set[str] = set()
    worktrees: set[str] = set()
    agent_seen: set[str] = set()
    all_rows = rows(registry)
    all_ids = {str(item.get("id")) for item in all_rows}
    for row in all_rows:
        wid, branch, worktree = str(row.get("id", "")), str(row.get("branch", "")), str(row.get("worktree", ""))
        if not wid or wid in ids:
            errors.append(f"invalid/duplicate workstream id: {wid!r}")
        ids.add(wid)
        if not branch or branch in branches:
            errors.append(f"invalid/duplicate workstream branch: {branch!r}")
        branches.add(branch)
        if not worktree or worktree in worktrees:
            errors.append(f"invalid/duplicate worktree: {worktree!r}")
        worktrees.add(worktree)
        slot = row.get("slot_status")
        if slot not in VALID_SLOT:
            errors.append(f"{wid}: invalid slot_status {slot!r}")
        agent = row.get("assigned_agent")
        role = row.get("role")
        if slot == "open" and agent:
            errors.append(f"{wid}: open slot cannot have assigned_agent")
        if slot == "occupied" and not agent:
            errors.append(f"{wid}: occupied slot requires assigned_agent")
        if agent:
            if str(agent) in agent_seen:
                errors.append(f"agent assigned to multiple workstreams: {agent}")
            agent_seen.add(str(agent))
        if role == "supervisor" and agent != control.get("supervisor_agent"):
            errors.append(f"{wid}: Supervisor lane must be assigned to {control.get('supervisor_agent')}")
        if row.get("merge_strategy") != control.get("default_pr_merge_strategy"):
            errors.append(f"{wid}: merge strategy drift")
        for dep in row.get("dependencies", []):
            if dep not in all_ids:
                errors.append(f"{wid}: unknown dependency {dep}")

    shared_paths = shared.get("supervisor_owned_paths", [])
    if not isinstance(shared_paths, list):
        errors.append("SHARED-PATHS supervisor_owned_paths must be a list")
        shared_paths = []
    writable_rows = [row for row in all_rows if row.get("status") in WRITABLE]
    if len(writable_rows) > hard:
        errors.append(f"active writer count {len(writable_rows)} exceeds hard cap {hard}")
    for i, left in enumerate(writable_rows):
        for right in writable_rows[i + 1:]:
            for a in left.get("write_paths", []):
                for b in right.get("write_paths", []):
                    if overlaps(str(a), str(b)):
                        errors.append(f"active write-scope overlap: {left.get('id')} {a} <-> {right.get('id')} {b}")
        if left.get("role") != "supervisor":
            for owned in left.get("write_paths", []):
                for locked in shared_paths:
                    if overlaps(str(owned), str(locked)):
                        errors.append(f"{left.get('id')}: worker scope overlaps Supervisor-owned path: {owned} <-> {locked}")

    lease_rows = leases.get("leases", [])
    if not isinstance(lease_rows, list):
        errors.append("AGENT-LEASES leases must be a list")
        lease_rows = []
    lease_agents: set[str] = set()
    lease_workstreams: set[str] = set()
    mapping = by_id(registry)
    for lease in lease_rows:
        if not isinstance(lease, dict):
            errors.append("lease entries must be objects")
            continue
        agent, wid = str(lease.get("agent", "")), str(lease.get("workstream", ""))
        if agent in lease_agents:
            errors.append(f"duplicate lease for agent {agent}")
        lease_agents.add(agent)
        if wid in lease_workstreams:
            errors.append(f"duplicate lease for workstream {wid}")
        lease_workstreams.add(wid)
        if wid not in mapping:
            errors.append(f"lease references unknown workstream {wid}")
        elif mapping[wid].get("assigned_agent") != agent:
            errors.append(f"lease agent mismatch for {wid}")
    if mode == "staged" and lease_rows:
        errors.append("staged workstreams must have zero leases")
    if registry.get("branches_precreated") is not True:
        errors.append("WORKSTREAMS branches_precreated must be true")
    return errors


def remote_branch_exists(branch: str) -> bool:
    result = git("ls-remote", "--exit-code", "--heads", "origin", f"refs/heads/{branch}", check=False)
    return result.returncode == 0 and bool(result.stdout.strip())


def validate_remote_branches() -> list[str]:
    registry = load(WORKSTREAMS)
    return [f"missing pre-created remote branch: {row.get('branch')}" for row in rows(registry) if not remote_branch_exists(str(row.get("branch", "")))]


def sync_check(branch: str | None) -> list[str]:
    registry = load(WORKSTREAMS)
    control = load(CONTROL)
    current = branch or branch_name()
    if by_branch(registry).get(current) is None:
        return []
    main = str(control.get("protected_main_branch", "main"))
    git("fetch", "origin", main, "--quiet", check=False)
    candidate = f"origin/{main}"
    if git("rev-parse", "--verify", candidate, check=False).returncode != 0:
        candidate = main
    if git("merge-base", "--is-ancestor", candidate, "HEAD", check=False).returncode != 0:
        return [f"registered branch {current} is stale; merge latest {main} before resuming/submitting"]
    return []


def validate_pr_event(path: Path) -> list[str]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    pr = payload.get("pull_request")
    if not isinstance(pr, dict):
        return []
    head = pr.get("head", {}).get("ref")
    row = by_branch(load(WORKSTREAMS)).get(str(head))
    if row is None:
        return []
    errors: list[str] = []
    if pr.get("base", {}).get("ref") != "main":
        errors.append(f"registered workstream PR {head} must target main")
    body = pr.get("body") or ""
    marker = f"Workstream: {row.get('id')}"
    if not standalone(body, marker):
        errors.append(f"registered workstream PR must contain standalone line: {marker}")
    if not pr.get("draft"):
        signal = str(load(CONTROL).get("required_completion_signal"))
        if not standalone(body, signal):
            errors.append(f"non-draft workstream PR must contain exact standalone signal: {signal}")
    return errors


def onboarding_check(branch: str | None) -> tuple[int, str]:
    control, registry = load(CONTROL), load(WORKSTREAMS)
    current = branch or branch_name()
    main = str(control.get("protected_main_branch", "main"))
    if current != main:
        return 2, f"New agent onboarding must start from {main}."
    slots = open_slots(registry)
    if not slots:
        return 3, str(control.get("onboarding_no_slot_message"))
    return 0, "Open slots: " + ", ".join(str(row.get("id")) for row in slots)


def render_plan_table(registry: dict[str, Any]) -> str:
    lines = [
        "| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |",
        "|---:|---|---|---|---|---|---|---|---|",
    ]
    for row in sorted(rows(registry), key=lambda r: (int(r.get("merge_group", 999)), str(r.get("id", "")))):
        agent = f"`{row.get('assigned_agent')}`" if row.get("assigned_agent") else "—"
        slot = "**OPEN**" if row.get("slot_status") == "open" else "`occupied`"
        lines.append(f"| {row.get('merge_group')} | {row.get('id')} | {row.get('capability')} | {slot} | {agent} | `{row.get('start_status')}` | `{row.get('branch')}` | {row.get('merge_strategy')} | merge latest main before resume |")
    return "\n".join(lines)


def refresh_plan(registry: dict[str, Any]) -> None:
    text = PLAN.read_text(encoding="utf-8")
    start, end = "<!-- WORKSTREAM_TABLE_START -->", "<!-- WORKSTREAM_TABLE_END -->"
    if start not in text or end not in text:
        raise ValueError("AI-NATIVE-PLAN is missing workstream table markers")
    before, rest = text.split(start, 1)
    _, after = rest.split(end, 1)
    PLAN.write_text(before + start + "\n" + render_plan_table(registry) + "\n" + end + after, encoding="utf-8")


def onboard(agent: str, start_branch: str) -> tuple[int, str]:
    control, registry = load(CONTROL), load(WORKSTREAMS)
    main = str(control.get("protected_main_branch", "main"))
    if start_branch != main:
        return 2, f"New agent onboarding must start from {main}."
    if any(row.get("assigned_agent") == agent for row in rows(registry)):
        return 2, f"Agent {agent} is already assigned."
    slots = open_slots(registry)
    if not slots:
        return 3, str(control.get("onboarding_no_slot_message"))
    slot = slots[0]
    slot["assigned_agent"] = agent
    slot["slot_status"] = "occupied"
    slot["onboarded_from_branch"] = main
    if registry.get("mode") == "staged":
        slot["start_status"] = "assigned_waiting_for_task_activation"
    else:
        mapping = by_id(registry)
        deps_ready = all(mapping.get(dep, {}).get("status") == "merged" for dep in slot.get("dependencies", []))
        slot["start_status"] = "ready_for_lease" if deps_ready else "assigned_waiting_for_dependencies"
    dump(WORKSTREAMS, registry)
    refresh_plan(registry)
    return 0, f"Assigned {agent} to {slot.get('id')} on {slot.get('branch')}"


def status() -> None:
    control, registry, leases = load(CONTROL), load(WORKSTREAMS), load(LEASES)
    print(f"Parallel mode: {registry.get('mode')}")
    print(f"Parent task: {registry.get('parent_task')} (canonical active: {current_active_task()})")
    print(f"Supervisor: {control.get('supervisor_agent')}")
    print(f"Open worker slots: {len(open_slots(registry))}")
    print(f"Active leases: {len(leases.get('leases', []))}")
    for row in sorted(rows(registry), key=lambda r: (int(r.get("merge_group", 999)), str(r.get("id", "")))):
        print(f"- {row.get('id')}: slot={row.get('slot_status')} agent={row.get('assigned_agent')} branch={row.get('branch')} start={row.get('start_status')}")


def print_errors(errors: list[str]) -> int:
    if not errors:
        return 0
    for error in errors:
        print(f"ERROR: {error}", file=sys.stderr)
    return 1


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    sub.add_parser("validate-remote-branches")
    sub.add_parser("status")
    sub.add_parser("fingerprint")
    sync = sub.add_parser("sync-check"); sync.add_argument("--branch")
    pr = sub.add_parser("validate-pr-event"); pr.add_argument("--event-path", default=os.environ.get("GITHUB_EVENT_PATH"))
    oc = sub.add_parser("onboarding-check"); oc.add_argument("--branch")
    ob = sub.add_parser("onboard"); ob.add_argument("--agent", required=True); ob.add_argument("--agent-start-branch", required=True)
    args = parser.parse_args()
    try:
        if args.command == "validate":
            errors = validate()
            if errors: return print_errors(errors)
            print("Parallel development validation PASSED"); return 0
        if args.command == "validate-remote-branches":
            errors = validate_remote_branches()
            if errors: return print_errors(errors)
            print("All required pre-created remote workstream branches exist."); return 0
        if args.command == "status":
            errors = validate()
            if errors: return print_errors(errors)
            status(); return 0
        if args.command == "fingerprint":
            print(instruction_fingerprint(load(CONTROL))); return 0
        if args.command == "sync-check":
            errors = sync_check(args.branch)
            if errors: return print_errors(errors)
            print("Parallel main-sync check PASSED"); return 0
        if args.command == "validate-pr-event":
            if not args.event_path:
                print("No PR event path; skipping registered workstream PR validation."); return 0
            return print_errors(validate_pr_event(Path(args.event_path)))
        if args.command == "onboarding-check":
            code, message = onboarding_check(args.branch); print(message); return code
        if args.command == "onboard":
            code, message = onboard(args.agent, args.agent_start_branch); print(message); return code
    except (ValueError, OSError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print(f"Parallel development error: {exc}", file=sys.stderr); return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
