#!/usr/bin/env python3
"""Repository-native continuity validator and state transition tool for VSN Marketing.

State/task .yaml files intentionally contain JSON, which is valid YAML 1.2,
so this tool has zero third-party runtime dependencies.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AI = ROOT / ".ai"
STATE_PATH = AI / "state" / "CURRENT-STATE.yaml"
TEST_PATH = AI / "state" / "TEST-STATE.yaml"
INDEX_PATH = AI / "tasks" / "INDEX.yaml"
ROADMAP_PATH = AI / "roadmap" / "ROADMAP.yaml"
CHECKPOINT_PATH = AI / "state" / "LAST-CHECKPOINT.md"

REQUIRED_FILES = [
    ROOT / "AGENTS.md",
    ROOT / "CLAUDE.md",
    AI / "00-PROJECT-CHARTER.md",
    AI / "01-MASTER-ARCHITECTURE.md",
    AI / "02-MODULE-REGISTRY.md",
    AI / "04-INTEGRATION-STANDARD.md",
    AI / "05-AI-RULES.md",
    AI / "06-SECURITY-RULES.md",
    AI / "08-TESTING-STANDARDS.md",
    AI / "09-DEFINITION-OF-DONE.md",
    STATE_PATH,
    TEST_PATH,
    INDEX_PATH,
    ROADMAP_PATH,
    CHECKPOINT_PATH,
]

TASK_STATUSES = {"planned", "ready", "in_progress", "blocked", "completed"}
EXEC_STATUSES = {"ready", "in_progress", "blocked", "needs_reconciliation"}
PHASE_STATUSES = {"planned", "in_progress", "blocked", "completed"}


def load_json_yaml(path: Path):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise ValueError(f"missing file: {path.relative_to(ROOT)}")
    except json.JSONDecodeError as exc:
        raise ValueError(f"{path.relative_to(ROOT)} is not valid JSON-compatible YAML: {exc}")


def write_json_yaml(path: Path, data) -> None:
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def task_path(task_id: str) -> Path:
    return AI / "tasks" / f"{task_id}.yaml"


def state_fingerprint(state: dict) -> str:
    payload = {
        "schema_version": state.get("schema_version"),
        "execution": state.get("execution", {}),
        "progress": state.get("progress", {}),
        "blockers": state.get("blockers", []),
        "exact_next_action": state.get("exact_next_action", ""),
    }
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def checkpoint_body(state: dict, *, summary: str, tests: str, next_action: str, timestamp: str | None = None) -> str:
    stamp = timestamp or datetime.now(timezone.utc).isoformat(timespec="seconds")
    active_id = state["execution"]["active_task"]
    next_id = state["execution"].get("next_task")
    fingerprint = state_fingerprint(state)
    blockers = "\n".join(f"- {item}" for item in state.get("blockers", [])) or "- None"
    return f"""# Last Checkpoint

## State

- Timestamp: `{stamp}`
- Active task: `{active_id}`
- Next task: `{next_id or 'none'}`
- Current phase: `{state['execution']['current_phase']}`
- Execution status: `{state['execution']['status']}`
- State fingerprint: `{fingerprint}`

## Completed / observed this session

{summary.strip()}

## Tests

{tests.strip()}

## Blockers

{blockers}

## Exact next action

{next_action.strip()}
"""


def detect_cycle(tasks_by_id: dict[str, dict]) -> list[str] | None:
    visiting, visited = set(), set()

    def walk(task_id: str, chain: list[str]):
        if task_id in visiting:
            return chain + [task_id]
        if task_id in visited:
            return None
        visiting.add(task_id)
        for dep in tasks_by_id[task_id].get("dependencies", []):
            if dep in tasks_by_id:
                found = walk(dep, chain + [task_id])
                if found:
                    return found
        visiting.remove(task_id)
        visited.add(task_id)
        return None

    for task_id in tasks_by_id:
        found = walk(task_id, [])
        if found:
            return found
    return None


def calculate_progress(index: dict, roadmap: dict, current_phase: str) -> tuple[float, float]:
    phase_rows = roadmap.get("phases", [])
    registry_rows = index.get("tasks", [])
    phase_tasks = [row for row in registry_rows if row.get("phase") == current_phase]
    phase_total = sum(float(row.get("weight", 0)) for row in phase_tasks)
    phase_completed = sum(float(row.get("weight", 0)) for row in phase_tasks if row.get("status") == "completed")
    phase_percent = round((phase_completed / phase_total) * 100, 2) if phase_total else 0.0
    roadmap_percent = 0.0
    for phase in phase_rows:
        phase_id = phase.get("id")
        weight = float(phase.get("weight", 0))
        tasks = [row for row in registry_rows if row.get("phase") == phase_id]
        if tasks:
            total = sum(float(row.get("weight", 0)) for row in tasks)
            completed = sum(float(row.get("weight", 0)) for row in tasks if row.get("status") == "completed")
            roadmap_percent += weight * ((completed / total) if total else 0.0)
        elif phase.get("status") == "completed":
            roadmap_percent += weight
    return phase_percent, round(roadmap_percent, 2)


def load_tasks(index: dict) -> tuple[dict[str, dict], list[str]]:
    tasks_by_id: dict[str, dict] = {}
    errors: list[str] = []
    for row in index.get("tasks", []):
        tid = row.get("id")
        if not tid:
            errors.append("task registry row without id")
            continue
        path = task_path(tid)
        if not path.exists():
            errors.append(f"registered task file missing: {path.relative_to(ROOT)}")
            continue
        try:
            tasks_by_id[tid] = load_json_yaml(path)
        except ValueError as exc:
            errors.append(str(exc))
    return tasks_by_id, errors


def validate() -> list[str]:
    errors: list[str] = []
    for path in REQUIRED_FILES:
        if not path.exists():
            errors.append(f"required file missing: {path.relative_to(ROOT)}")
    if errors:
        return errors
    try:
        state = load_json_yaml(STATE_PATH)
        index = load_json_yaml(INDEX_PATH)
        roadmap = load_json_yaml(ROADMAP_PATH)
        test_state = load_json_yaml(TEST_PATH)
    except ValueError as exc:
        return [str(exc)]

    execution = state.get("execution", {})
    if execution.get("status") not in EXEC_STATUSES:
        errors.append(f"invalid execution.status: {execution.get('status')!r}")
    phase_rows = roadmap.get("phases", [])
    phase_ids = [row.get("id") for row in phase_rows]
    if len(phase_ids) != len(set(phase_ids)):
        errors.append("duplicate phase IDs in ROADMAP.yaml")
    if sum(float(row.get("weight", 0)) for row in phase_rows) != 100:
        errors.append("roadmap phase weights must sum to 100")
    for row in phase_rows:
        if row.get("status") not in PHASE_STATUSES:
            errors.append(f"invalid status for {row.get('id')}: {row.get('status')!r}")
    current_phase = execution.get("current_phase")
    if current_phase not in phase_ids:
        errors.append(f"current phase {current_phase!r} is absent from ROADMAP.yaml")

    registry_rows = index.get("tasks", [])
    registry_ids = [row.get("id") for row in registry_rows]
    if len(registry_ids) != len(set(registry_ids)):
        errors.append("duplicate task IDs in INDEX.yaml")
    tasks_by_id, task_load_errors = load_tasks(index)
    errors.extend(task_load_errors)
    registry_by_id = {row.get("id"): row for row in registry_rows}

    for row in registry_rows:
        tid = row.get("id")
        if not tid or tid not in tasks_by_id:
            continue
        task = tasks_by_id[tid]
        if task.get("id") != tid:
            errors.append(f"{tid}: task file id mismatch")
        for key in ("phase", "title", "status", "dependencies"):
            if task.get(key) != row.get(key):
                errors.append(f"{tid}: {key} differs between task file and INDEX.yaml")
        if task.get("status") not in TASK_STATUSES:
            errors.append(f"{tid}: invalid task status {task.get('status')!r}")
        if task.get("phase") not in phase_ids:
            errors.append(f"{tid}: unknown phase {task.get('phase')!r}")
        for dep in task.get("dependencies", []):
            if dep == tid:
                errors.append(f"{tid}: self dependency")
            elif dep not in registry_by_id:
                errors.append(f"{tid}: missing dependency {dep}")
        criteria = task.get("acceptance_criteria", [])
        if task.get("status") == "completed" and any(not item.get("done") for item in criteria):
            errors.append(f"{tid}: completed task has incomplete acceptance criteria")

    if len(tasks_by_id) == len(registry_rows):
        cycle = detect_cycle(tasks_by_id)
        if cycle:
            errors.append("task dependency cycle: " + " -> ".join(cycle))

    active_id = execution.get("active_task")
    active = tasks_by_id.get(active_id)
    if not active_id or not active:
        errors.append(f"active task {active_id!r} is missing from task registry/files")
    else:
        if active.get("status") not in {"ready", "in_progress", "blocked"}:
            errors.append(f"active task {active_id} has illegal active status {active.get('status')!r}")
        incomplete_deps = [dep for dep in active.get("dependencies", []) if tasks_by_id.get(dep, {}).get("status") != "completed"]
        if incomplete_deps:
            errors.append(f"active task {active_id} has incomplete dependencies: {', '.join(incomplete_deps)}")
        if not str(active.get("exact_next_action", "")).strip():
            errors.append(f"active task {active_id} must contain exact_next_action")
        exec_status = execution.get("status")
        if exec_status in {"ready", "in_progress", "blocked"} and active.get("status") != exec_status:
            errors.append(f"execution.status {exec_status!r} must match active task status {active.get('status')!r}")

    next_id = execution.get("next_task")
    if next_id is not None:
        if next_id not in tasks_by_id:
            errors.append(f"next task {next_id!r} is missing from task registry/files")
        elif next_id == active_id:
            errors.append("next_task cannot equal active_task")
        elif tasks_by_id[next_id].get("status") == "completed":
            errors.append(f"next task {next_id} is already completed")

    in_progress = [tid for tid, task in tasks_by_id.items() if task.get("status") == "in_progress"]
    if len(in_progress) > 1:
        errors.append("more than one task is in_progress: " + ", ".join(in_progress))
    if in_progress and active_id not in in_progress:
        errors.append("the in_progress task is not the active task")
    last_completed = execution.get("last_completed_task")
    if last_completed and tasks_by_id.get(last_completed, {}).get("status") != "completed":
        errors.append(f"last_completed_task {last_completed!r} is not completed")

    checkpoint = CHECKPOINT_PATH.read_text(encoding="utf-8")
    if active_id and active_id not in checkpoint:
        errors.append("LAST-CHECKPOINT.md does not mention the active task")
    next_action = str(state.get("exact_next_action", "")).strip()
    if not next_action:
        errors.append("CURRENT-STATE.yaml must contain exact_next_action")
    elif next_action not in checkpoint:
        errors.append("LAST-CHECKPOINT.md does not contain CURRENT-STATE exact_next_action")
    expected_fingerprint = state_fingerprint(state)
    if f"State fingerprint: `{expected_fingerprint}`" not in checkpoint:
        errors.append("LAST-CHECKPOINT.md fingerprint does not match CURRENT-STATE.yaml; update both atomically with `python tools/ai_state.py checkpoint`")

    if current_phase in phase_ids:
        calculated_phase, calculated_roadmap = calculate_progress(index, roadmap, current_phase)
        recorded_phase = float(state.get("progress", {}).get("phase_percent", -1))
        if abs(calculated_phase - recorded_phase) > 0.01:
            errors.append(f"phase_percent drift: recorded {recorded_phase}, calculated {calculated_phase}")
        recorded_roadmap = float(state.get("progress", {}).get("roadmap_percent", -1))
        if abs(calculated_roadmap - recorded_roadmap) > 0.01:
            errors.append(f"roadmap_percent drift: recorded {recorded_roadmap}, calculated {calculated_roadmap}")

    failing = [suite.get("name", "unnamed") for suite in test_state.get("suites", []) if suite.get("status") == "failing"]
    if execution.get("status") == "ready" and failing:
        errors.append("execution is ready while required test state contains failures: " + ", ".join(failing))
    if execution.get("status") == "blocked" and not state.get("blockers"):
        errors.append("execution.status is blocked but CURRENT-STATE contains no blockers")
    return errors


def print_status() -> None:
    state = load_json_yaml(STATE_PATH)
    task = load_json_yaml(task_path(state["execution"]["active_task"]))
    p = state["progress"]
    print(f"PROJECT        {state['project']['name']}")
    print(f"PHASE          {state['execution']['current_phase']}")
    print(f"TASK           {task['id']} — {task['title']}")
    print(f"TASK STATUS    {task['status']}")
    print(f"EXECUTION      {state['execution']['status']}")
    print(f"PHASE          {p['phase_percent']:.2f}%")
    print(f"ROADMAP        {p['roadmap_percent']:.2f}%")
    blockers = state.get("blockers", [])
    print(f"BLOCKERS       {len(blockers)}")
    for blocker in blockers:
        print(f"  - {blocker}")
    print("NEXT ACTION")
    print(state["exact_next_action"])


def checkpoint(summary: str, next_action: str, tests: str) -> None:
    state = load_json_yaml(STATE_PATH)
    state["exact_next_action"] = next_action.strip()
    write_json_yaml(STATE_PATH, state)
    CHECKPOINT_PATH.write_text(checkpoint_body(state, summary=summary, tests=tests, next_action=state["exact_next_action"]), encoding="utf-8")
    print(f"Checkpoint updated for {state['execution']['active_task']}")


def git_changed_files(args: list[str]) -> tuple[set[str], str | None]:
    proc = subprocess.run(["git", *args], cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if proc.returncode != 0:
        return set(), proc.stderr.strip() or f"git {' '.join(args)} failed"
    return {line.strip() for line in proc.stdout.splitlines() if line.strip()}, None


def is_product_change(path: str) -> bool:
    exempt_prefixes = (".ai/", ".github/", "docs/", "tools/")
    exempt_files = {"README.md", "AGENTS.md", "CLAUDE.md", "LICENSE"}
    return path not in exempt_files and not path.startswith(exempt_prefixes)


def verify_changed_paths(changed: set[str], label: str) -> list[str]:
    if not changed:
        return []
    product_changes = sorted(path for path in changed if is_product_change(path))
    if not product_changes:
        return []
    required = {".ai/state/CURRENT-STATE.yaml", ".ai/state/LAST-CHECKPOINT.md"}
    missing = sorted(required - changed)
    if missing:
        return [f"{label}: product/source changes detected without synchronized continuity ledger updates; missing {', '.join(missing)}. Product changes: {', '.join(product_changes[:20])}"]
    return []


def verify_change_set(base: str, head: str) -> list[str]:
    changed, error = git_changed_files(["diff", "--name-only", base, head])
    if error:
        return [f"git diff failed: {error}"]
    return verify_changed_paths(changed, "commit range")


def working_tree_paths() -> tuple[set[str], str | None]:
    proc = subprocess.run(["git", "status", "--porcelain=v1"], cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if proc.returncode != 0:
        return set(), proc.stderr.strip() or "git status failed"
    changed: set[str] = set()
    for raw in proc.stdout.splitlines():
        if not raw:
            continue
        path = raw[3:].strip()
        if " -> " in path:
            path = path.split(" -> ", 1)[1]
        changed.add(path)
    return changed, None


def recover() -> int:
    errors = validate()
    changed, git_error = working_tree_paths()
    print("VSN Marketing continuity recovery report")
    print("=" * 40)
    if errors:
        print("LEDGER         INVALID")
        for error in errors:
            print(f"  - {error}")
    else:
        print("LEDGER         VALID")
    if git_error:
        print(f"GIT            ERROR — {git_error}")
    elif changed:
        print(f"WORKTREE       {len(changed)} changed path(s)")
        for path in sorted(changed)[:50]:
            print(f"  - {path}")
    else:
        print("WORKTREE       CLEAN")
    if not git_error:
        drift = verify_changed_paths(changed, "working tree")
        if drift:
            print("HANDOFF DRIFT  DETECTED")
            for error in drift:
                print(f"  - {error}")
        else:
            print("HANDOFF DRIFT  NONE")
    if not errors:
        print()
        print_status()
    return 1 if errors or git_error else 0


def next_registry_task(index: dict, current_id: str) -> str | None:
    rows = index.get("tasks", [])
    seen = False
    for row in rows:
        if row.get("id") == current_id:
            seen = True
            continue
        if seen and row.get("status") != "completed":
            return row.get("id")
    return None


def transition_task(complete_id: str, next_id: str, evidence: str, tests: str, *, dry_run: bool = False) -> list[str]:
    initial_errors = validate()
    if initial_errors:
        return ["pre-transition validation failed"] + initial_errors
    state = load_json_yaml(STATE_PATH)
    index = load_json_yaml(INDEX_PATH)
    roadmap = load_json_yaml(ROADMAP_PATH)
    tasks, errors = load_tasks(index)
    if errors:
        return errors
    active_id = state["execution"]["active_task"]
    if active_id != complete_id:
        return [f"cannot complete {complete_id}; active task is {active_id}"]
    if next_id not in tasks:
        return [f"next task {next_id} does not exist"]
    if next_id == complete_id:
        return ["next task cannot equal completed task"]
    current = json.loads(json.dumps(tasks[complete_id]))
    next_task = json.loads(json.dumps(tasks[next_id]))
    incomplete_criteria = [item.get("id", "unknown") for item in current.get("acceptance_criteria", []) if not item.get("done")]
    if incomplete_criteria:
        return [f"{complete_id} cannot complete; acceptance criteria are false: " + ", ".join(incomplete_criteria)]
    current["status"] = "completed"
    simulated_status = {tid: task.get("status") for tid, task in tasks.items()}
    simulated_status[complete_id] = "completed"
    incomplete_deps = [dep for dep in next_task.get("dependencies", []) if simulated_status.get(dep) != "completed"]
    if incomplete_deps:
        return [f"{next_id} cannot activate; incomplete dependencies: " + ", ".join(incomplete_deps)]
    if next_task.get("status") == "completed":
        return [f"{next_id} is already completed"]
    next_task["status"] = "ready"
    new_index = json.loads(json.dumps(index))
    for row in new_index.get("tasks", []):
        if row.get("id") == complete_id:
            row["status"] = "completed"
        elif row.get("id") == next_id:
            row["status"] = "ready"
    new_roadmap = json.loads(json.dumps(roadmap))
    old_phase = current.get("phase")
    new_phase = next_task.get("phase")
    phase_has_remaining = any(row.get("phase") == old_phase and row.get("id") != complete_id and row.get("status") != "completed" for row in new_index.get("tasks", []))
    for phase in new_roadmap.get("phases", []):
        if phase.get("id") == old_phase and old_phase != new_phase and not phase_has_remaining:
            phase["status"] = "completed"
        if phase.get("id") == new_phase and phase.get("status") == "planned":
            phase["status"] = "in_progress"
    new_state = json.loads(json.dumps(state))
    new_state["execution"]["status"] = "ready"
    new_state["execution"]["current_phase"] = new_phase
    new_state["execution"]["active_task"] = next_id
    new_state["execution"]["last_completed_task"] = complete_id
    new_state["execution"]["next_task"] = next_registry_task(new_index, next_id)
    new_state["blockers"] = []
    phase_percent, roadmap_percent = calculate_progress(new_index, new_roadmap, new_phase)
    new_state["progress"]["phase_percent"] = phase_percent
    new_state["progress"]["roadmap_percent"] = roadmap_percent
    new_state["progress"]["calculation"] = "Calculated deterministically from task weights and completed task statuses."
    new_state["exact_next_action"] = str(next_task.get("exact_next_action", "")).strip()
    if not new_state["exact_next_action"]:
        return [f"{next_id} must contain exact_next_action before transition"]
    if dry_run:
        print(f"Transition valid: {complete_id} -> {next_id}; phase={new_phase}; roadmap={roadmap_percent:.2f}%")
        return []
    snapshots = {
        task_path(complete_id): task_path(complete_id).read_text(encoding="utf-8"),
        task_path(next_id): task_path(next_id).read_text(encoding="utf-8"),
        INDEX_PATH: INDEX_PATH.read_text(encoding="utf-8"),
        ROADMAP_PATH: ROADMAP_PATH.read_text(encoding="utf-8"),
        STATE_PATH: STATE_PATH.read_text(encoding="utf-8"),
        CHECKPOINT_PATH: CHECKPOINT_PATH.read_text(encoding="utf-8"),
    }
    try:
        write_json_yaml(task_path(complete_id), current)
        write_json_yaml(task_path(next_id), next_task)
        write_json_yaml(INDEX_PATH, new_index)
        write_json_yaml(ROADMAP_PATH, new_roadmap)
        write_json_yaml(STATE_PATH, new_state)
        CHECKPOINT_PATH.write_text(checkpoint_body(new_state, summary=f"Completed `{complete_id}` and activated `{next_id}`.\n\nTransition evidence: {evidence.strip()}", tests=tests, next_action=new_state["exact_next_action"]), encoding="utf-8")
        post_errors = validate()
        if post_errors:
            raise ValueError("post-transition validation failed: " + "; ".join(post_errors))
    except Exception as exc:
        for path, content in snapshots.items():
            path.write_text(content, encoding="utf-8")
        return [f"transition rolled back: {exc}"]
    print(f"Transition complete: {complete_id} -> {next_id}")
    return []


def main() -> int:
    parser = argparse.ArgumentParser(description="VSN Marketing AI continuity control")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    sub.add_parser("status")
    sub.add_parser("recover")
    cp = sub.add_parser("checkpoint")
    cp.add_argument("--summary", required=True)
    cp.add_argument("--next", dest="next_action", required=True)
    cp.add_argument("--tests", default="Not run; record why before handing off.")
    drift = sub.add_parser("verify-change-set")
    drift.add_argument("--base", required=True)
    drift.add_argument("--head", required=True)
    transition = sub.add_parser("transition")
    transition.add_argument("--complete", required=True)
    transition.add_argument("--next", dest="next_task", required=True)
    transition.add_argument("--evidence", required=True)
    transition.add_argument("--tests", required=True)
    transition.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        if args.command == "validate":
            errors = validate()
            if errors:
                print("AI continuity validation FAILED:", file=sys.stderr)
                for error in errors:
                    print(f"- {error}", file=sys.stderr)
                return 1
            print("AI continuity validation PASSED")
            return 0
        if args.command == "status":
            errors = validate()
            if errors:
                for error in errors:
                    print(f"ERROR: {error}", file=sys.stderr)
                return 1
            print_status()
            return 0
        if args.command == "recover":
            return recover()
        if args.command == "checkpoint":
            checkpoint(args.summary, args.next_action, args.tests)
            errors = validate()
            if errors:
                print("Checkpoint written, but validation now fails:", file=sys.stderr)
                for error in errors:
                    print(f"- {error}", file=sys.stderr)
                return 1
            return 0
        if args.command == "verify-change-set":
            errors = verify_change_set(args.base, args.head)
            if errors:
                for error in errors:
                    print(f"ERROR: {error}", file=sys.stderr)
                return 1
            print("Change-set continuity check PASSED")
            return 0
        if args.command == "transition":
            errors = transition_task(args.complete, args.next_task, args.evidence, args.tests, dry_run=args.dry_run)
            if errors:
                for error in errors:
                    print(f"ERROR: {error}", file=sys.stderr)
                return 1
            return 0
    except (KeyError, ValueError, TypeError) as exc:
        print(f"AI continuity tool error: {exc}", file=sys.stderr)
        return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
