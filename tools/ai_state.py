#!/usr/bin/env python3
"""Repository-native continuity validator for VSN Marketing.

State/task .yaml files intentionally contain JSON, which is valid YAML 1.2,
so this tool has zero third-party runtime dependencies.
"""
from __future__ import annotations

import argparse
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


def detect_cycle(tasks_by_id: dict[str, dict]) -> list[str] | None:
    visiting, visited = set(), set()

    def walk(task_id: str, chain: list[str]):
        if task_id in visiting:
            return chain + [task_id]
        if task_id in visited:
            return None
        visiting.add(task_id)
        for dep in tasks_by_id[task_id].get("dependencies", []):
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
    if sum(row.get("weight", 0) for row in phase_rows) != 100:
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

    tasks_by_id: dict[str, dict] = {}
    registry_by_id = {row.get("id"): row for row in registry_rows}
    for row in registry_rows:
        tid = row.get("id")
        if not tid:
            errors.append("task registry row without id")
            continue
        path = task_path(tid)
        if not path.exists():
            errors.append(f"registered task file missing: {path.relative_to(ROOT)}")
            continue
        try:
            task = load_json_yaml(path)
        except ValueError as exc:
            errors.append(str(exc))
            continue
        tasks_by_id[tid] = task
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
    if not active_id or active_id not in tasks_by_id:
        errors.append(f"active task {active_id!r} is missing from task registry/files")
    else:
        active = tasks_by_id[active_id]
        if active.get("status") not in {"ready", "in_progress", "blocked"}:
            errors.append(f"active task {active_id} has illegal active status {active.get('status')!r}")
        incomplete_deps = [d for d in active.get("dependencies", []) if tasks_by_id.get(d, {}).get("status") != "completed"]
        if incomplete_deps:
            errors.append(f"active task {active_id} has incomplete dependencies: {', '.join(incomplete_deps)}")

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
    next_action = state.get("exact_next_action", "").strip()
    if not next_action:
        errors.append("CURRENT-STATE.yaml must contain exact_next_action")

    phase_tasks = [row for row in registry_rows if row.get("phase") == current_phase]
    if phase_tasks:
        total = sum(float(row.get("weight", 0)) for row in phase_tasks)
        completed = sum(float(row.get("weight", 0)) for row in phase_tasks if row.get("status") == "completed")
        calculated_phase = round((completed / total) * 100, 2) if total else 0.0
        recorded_phase = float(state.get("progress", {}).get("phase_percent", -1))
        if abs(calculated_phase - recorded_phase) > 0.01:
            errors.append(f"phase_percent drift: recorded {recorded_phase}, calculated {calculated_phase}")

        phase_weight = next((float(row.get("weight", 0)) for row in phase_rows if row.get("id") == current_phase), 0.0)
        completed_prior_weight = 0.0
        for row in phase_rows:
            if row.get("id") == current_phase:
                break
            if row.get("status") == "completed":
                completed_prior_weight += float(row.get("weight", 0))
        calculated_roadmap = round(completed_prior_weight + phase_weight * (calculated_phase / 100), 2)
        recorded_roadmap = float(state.get("progress", {}).get("roadmap_percent", -1))
        if abs(calculated_roadmap - recorded_roadmap) > 0.01:
            errors.append(f"roadmap_percent drift: recorded {recorded_roadmap}, calculated {calculated_roadmap}")

    failing = [s.get("name", "unnamed") for s in test_state.get("suites", []) if s.get("status") == "failing"]
    if execution.get("status") == "ready" and failing:
        errors.append("execution is ready while required test state contains failures: " + ", ".join(failing))

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
    print("NEXT ACTION")
    print(state["exact_next_action"])


def checkpoint(summary: str, next_action: str, tests: str) -> None:
    state = load_json_yaml(STATE_PATH)
    active_id = state["execution"]["active_task"]
    stamp = datetime.now(timezone.utc).isoformat(timespec="seconds")
    body = f"""# Last Checkpoint\n\n## State\n\n- Timestamp: `{stamp}`\n- Active task: `{active_id}`\n- Execution status: `{state['execution']['status']}`\n\n## Completed / observed this session\n\n{summary.strip()}\n\n## Tests\n\n{tests.strip()}\n\n## Exact next action\n\n{next_action.strip()}\n"""
    CHECKPOINT_PATH.write_text(body, encoding="utf-8")
    state["exact_next_action"] = next_action.strip()
    write_json_yaml(STATE_PATH, state)
    print(f"Checkpoint updated for {active_id}")


def verify_change_set(base: str, head: str) -> list[str]:
    proc = subprocess.run(
        ["git", "diff", "--name-only", base, head],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        return [f"git diff failed: {proc.stderr.strip()}"]
    changed = {line.strip() for line in proc.stdout.splitlines() if line.strip()}
    if not changed:
        return []

    def is_product_change(path: str) -> bool:
        exempt_prefixes = (".ai/", ".github/", "docs/", "tools/")
        exempt_files = {"README.md", "AGENTS.md", "CLAUDE.md", "LICENSE"}
        return path not in exempt_files and not path.startswith(exempt_prefixes)

    product_changes = sorted(path for path in changed if is_product_change(path))
    if not product_changes:
        return []
    required = {".ai/state/CURRENT-STATE.yaml", ".ai/state/LAST-CHECKPOINT.md"}
    missing = sorted(required - changed)
    if missing:
        return [
            "product/source changes detected without synchronized continuity ledger updates; "
            f"missing {', '.join(missing)}. Product changes: {', '.join(product_changes[:20])}"
        ]
    return []


def main() -> int:
    parser = argparse.ArgumentParser(description="VSN Marketing AI continuity control")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    sub.add_parser("status")
    cp = sub.add_parser("checkpoint")
    cp.add_argument("--summary", required=True)
    cp.add_argument("--next", dest="next_action", required=True)
    cp.add_argument("--tests", default="Not run; record why before handing off.")
    drift = sub.add_parser("verify-change-set")
    drift.add_argument("--base", required=True)
    drift.add_argument("--head", required=True)
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
    except (KeyError, ValueError, TypeError) as exc:
        print(f"AI continuity tool error: {exc}", file=sys.stderr)
        return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
