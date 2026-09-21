#!/usr/bin/env python3
"""Rolling, append-only, hash-chained execution journal for VSN Marketing."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STATE_PATH = ROOT / ".ai" / "state" / "CURRENT-STATE.yaml"
JOURNAL_PATH = ROOT / ".ai" / "state" / "EXECUTION-JOURNAL.jsonl"
JOURNAL_RELATIVE_PATH = ".ai/state/EXECUTION-JOURNAL.jsonl"
ARCHIVE_RELATIVE_DIR = ".ai/state/archive"
MAX_ACTIVE_BYTES = 32 * 1024
TARGET_ACTIVE_BYTES = 24 * 1024

ALLOWED_TYPES = {
    "bootstrap_sync", "checkpoint", "recovery", "task_started", "task_blocked",
    "task_unblocked", "task_transition", "architecture_decision", "manual_sync",
}


def archive_dir() -> Path:
    return JOURNAL_PATH.parent / "archive"


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def canonical_hash(data: dict) -> str:
    raw = json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def state_fingerprint(state: dict) -> str:
    payload = {
        "schema_version": state.get("schema_version"),
        "execution": state.get("execution", {}),
        "progress": state.get("progress", {}),
        "blockers": state.get("blockers", []),
        "exact_next_action": state.get("exact_next_action", ""),
        "observed_main_sha": state.get("observed_main_sha"),
        "active_issue": state.get("active_issue"),
        "active_pr": state.get("active_pr"),
        "active_branch": state.get("active_branch"),
        "current_milestone": state.get("current_milestone"),
        "milestone_status": state.get("milestone_status"),
        "last_completed_milestone": state.get("last_completed_milestone"),
        "exact_next_safe_action": state.get("exact_next_safe_action"),
        "pending_runner_ids": state.get("pending_runner_ids", []),
        "blocked_runner_ids": state.get("blocked_runner_ids", []),
        "current_blockers": state.get("current_blockers", []),
        "timeout_control": state.get("timeout_control", {}),
    }
    return canonical_hash(payload)


def nonempty_lines(text: str) -> list[str]:
    return [line for line in text.splitlines() if line.strip()]


def archive_paths() -> list[Path]:
    root = archive_dir()
    if not root.exists():
        return []
    return sorted(root.glob("EXECUTION-JOURNAL-*.jsonl"))


def history_lines() -> list[str]:
    if not JOURNAL_PATH.exists():
        raise ValueError("missing .ai/state/EXECUTION-JOURNAL.jsonl")
    lines: list[str] = []
    for path in archive_paths():
        lines.extend(nonempty_lines(path.read_text(encoding="utf-8")))
    lines.extend(nonempty_lines(JOURNAL_PATH.read_text(encoding="utf-8")))
    return lines


def read_events() -> list[dict]:
    events: list[dict] = []
    for line_no, raw in enumerate(history_lines(), start=1):
        try:
            event = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise ValueError(f"journal logical line {line_no} is invalid JSON: {exc}") from exc
        events.append(event)
    return events


def validate_append_only_lines(base_lines: list[str], current_lines: list[str]) -> list[str]:
    if len(current_lines) < len(base_lines):
        return [f"execution journal was truncated: base has {len(base_lines)} event line(s), current has {len(current_lines)}"]
    for index, old_line in enumerate(base_lines):
        if current_lines[index] != old_line:
            return [f"execution journal history was rewritten at line {index + 1}; existing event bytes are immutable and only new lines may be appended"]
    return []


def validate_preserved_lines(base_lines: list[str], current_history: list[str]) -> list[str]:
    if not base_lines:
        return []
    if len(current_history) < len(base_lines):
        return ["execution journal logical history is shorter than the base journal"]
    width = len(base_lines)
    for start in range(0, len(current_history) - width + 1):
        if current_history[start:start + width] == base_lines:
            return []
    return ["base execution-journal event bytes are not preserved in the rolling logical history"]


def git_show(base: str, path: str) -> str | None:
    proc = subprocess.run(["git", "show", f"{base}:{path}"], cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return proc.stdout if proc.returncode == 0 else None


def verify_append_only(base: str) -> list[str]:
    verify = subprocess.run(["git", "rev-parse", "--verify", f"{base}^{{commit}}"], cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if verify.returncode != 0:
        return [f"cannot verify journal base commit {base!r}: {verify.stderr.strip()}"]

    base_text = git_show(base, JOURNAL_RELATIVE_PATH)
    if base_text is None:
        return []
    errors = validate_preserved_lines(nonempty_lines(base_text), history_lines())

    tree = subprocess.run(
        ["git", "ls-tree", "-r", "--name-only", base, "--", ARCHIVE_RELATIVE_DIR],
        cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if tree.returncode == 0:
        for rel in [line.strip() for line in tree.stdout.splitlines() if line.strip()]:
            old = git_show(base, rel)
            current = ROOT / rel
            if old is None or not current.is_file() or current.read_text(encoding="utf-8") != old:
                errors.append(f"historical journal archive was changed or removed: {rel}")
    return errors


def _atomic_write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_name(path.name + ".tmp")
    with temp.open("w", encoding="utf-8", newline="\n") as handle:
        handle.write(text)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temp, path)


def compact() -> None:
    if not JOURNAL_PATH.exists():
        raise ValueError("missing active execution journal")
    text = JOURNAL_PATH.read_text(encoding="utf-8")
    if len(text.encode("utf-8")) <= TARGET_ACTIVE_BYTES:
        print("Active execution journal already within rolling target.")
        return
    lines = nonempty_lines(text)
    if len(lines) < 2:
        raise ValueError("cannot compact a single oversized journal event")

    keep: list[str] = []
    size = 0
    for raw in reversed(lines):
        row_size = len((raw + "\n").encode("utf-8"))
        if keep and size + row_size > TARGET_ACTIVE_BYTES:
            break
        keep.append(raw)
        size += row_size
    keep.reverse()
    move_count = len(lines) - len(keep)
    if move_count <= 0:
        raise ValueError("cannot reduce active journal below rolling target")
    moved = lines[:move_count]
    first = json.loads(moved[0]).get("seq")
    last = json.loads(moved[-1]).get("seq")
    if not isinstance(first, int) or not isinstance(last, int):
        raise ValueError("journal archive range requires integer sequence IDs")

    archived_text = "\n".join(moved) + "\n"
    active_text = "\n".join(keep) + "\n"
    archive = archive_dir() / f"EXECUTION-JOURNAL-{first:04d}-{last:04d}.jsonl"
    archive.parent.mkdir(parents=True, exist_ok=True)
    if archive.exists():
        if archive.read_text(encoding="utf-8") != archived_text:
            raise ValueError(f"archive collision with different bytes: {archive.name}")
    else:
        _atomic_write(archive, archived_text)
    _atomic_write(JOURNAL_PATH, active_text)
    print(f"Rolled journal events {first}-{last} into {archive.relative_to(ROOT)}")


def validate() -> list[str]:
    errors: list[str] = []
    try:
        state = load_json(STATE_PATH)
        events = read_events()
    except (FileNotFoundError, json.JSONDecodeError, ValueError) as exc:
        return [str(exc)]

    if JOURNAL_PATH.stat().st_size > MAX_ACTIVE_BYTES:
        errors.append(f"active execution journal exceeds {MAX_ACTIVE_BYTES} bytes; run compact")
    if not events:
        return errors + ["execution journal must contain at least one event"]

    previous_hash = "GENESIS"
    previous_seq = 0
    for idx, event in enumerate(events, start=1):
        seq = event.get("seq")
        if seq != previous_seq + 1:
            errors.append(f"journal event {idx}: sequence must be {previous_seq + 1}, got {seq!r}")
        if event.get("prev_hash") != previous_hash:
            errors.append(f"journal event {idx}: prev_hash does not match prior event")
        event_type = event.get("type")
        if event_type not in ALLOWED_TYPES:
            errors.append(f"journal event {idx}: unsupported type {event_type!r}")
        for required in ("timestamp", "task_id", "state_fingerprint", "summary", "hash"):
            if not event.get(required):
                errors.append(f"journal event {idx}: missing {required}")
        material = {key: value for key, value in event.items() if key != "hash"}
        if event.get("hash") != canonical_hash(material):
            errors.append(f"journal event {idx}: hash mismatch")
        previous_hash = str(event.get("hash", ""))
        previous_seq = seq if isinstance(seq, int) else previous_seq

    current_fp = state_fingerprint(state)
    last = events[-1]
    if last.get("state_fingerprint") != current_fp:
        errors.append("execution journal is stale relative to CURRENT-STATE.yaml; record a synchronized event after every state/checkpoint/transition change")
    active_task = state.get("execution", {}).get("active_task")
    if last.get("task_id") != active_task:
        errors.append(f"journal last task {last.get('task_id')!r} does not match active task {active_task!r}")
    return errors


def record(event_type: str, summary: str) -> None:
    if event_type not in ALLOWED_TYPES:
        raise ValueError(f"unsupported event type: {event_type}")
    state = load_json(STATE_PATH)
    events = read_events()
    previous_hash = events[-1]["hash"] if events else "GENESIS"
    seq = int(events[-1]["seq"]) + 1 if events else 1
    event = {
        "seq": seq,
        "timestamp": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "type": event_type,
        "task_id": state["execution"]["active_task"],
        "state_fingerprint": state_fingerprint(state),
        "summary": summary.strip(),
        "prev_hash": previous_hash,
    }
    event["hash"] = canonical_hash(event)
    line = json.dumps(event, ensure_ascii=False, separators=(",", ":")) + "\n"
    projected = JOURNAL_PATH.stat().st_size + len(line.encode("utf-8"))
    if projected > MAX_ACTIVE_BYTES:
        raise ValueError("journal append would exceed compact limit; run ai_journal.py compact before the transactional mutation")
    with JOURNAL_PATH.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(line)
    print(f"Recorded journal event #{seq}: {event_type}")


def status() -> None:
    state = load_json(STATE_PATH)
    events = read_events()
    last = events[-1]
    print(f"EVENTS         {len(events)}")
    print(f"ARCHIVES       {len(archive_paths())}")
    print(f"ACTIVE BYTES   {JOURNAL_PATH.stat().st_size}")
    print(f"LAST SEQ       {last['seq']}")
    print(f"LAST TYPE      {last['type']}")
    print(f"LAST TASK      {last['task_id']}")
    print(f"CHAIN HEAD     {last['hash']}")
    print(f"STATE MATCH    {last['state_fingerprint'] == state_fingerprint(state)}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    sub.add_parser("status")
    sub.add_parser("compact")
    rec = sub.add_parser("record")
    rec.add_argument("--type", required=True, choices=sorted(ALLOWED_TYPES))
    rec.add_argument("--summary", required=True)
    append = sub.add_parser("verify-append-only")
    append.add_argument("--base", required=True)
    args = parser.parse_args()
    try:
        if args.command == "validate":
            errors = validate()
            if errors:
                print("AI execution journal validation FAILED:", file=sys.stderr)
                for error in errors:
                    print(f"- {error}", file=sys.stderr)
                return 1
            print("AI execution journal validation PASSED")
            return 0
        if args.command == "status":
            errors = validate()
            if errors:
                for error in errors:
                    print(f"ERROR: {error}", file=sys.stderr)
                return 1
            status()
            return 0
        if args.command == "compact":
            compact()
            errors = validate()
            if errors:
                for error in errors:
                    print(f"ERROR: {error}", file=sys.stderr)
                return 1
            return 0
        if args.command == "record":
            record(args.type, args.summary)
            errors = validate()
            if errors:
                print("Journal event recorded, but validation fails:", file=sys.stderr)
                for error in errors:
                    print(f"- {error}", file=sys.stderr)
                return 1
            return 0
        if args.command == "verify-append-only":
            errors = verify_append_only(args.base)
            if errors:
                print("AI execution journal append-only check FAILED:", file=sys.stderr)
                for error in errors:
                    print(f"- {error}", file=sys.stderr)
                return 1
            print("AI execution journal append-only check PASSED")
            return 0
    except (KeyError, ValueError, TypeError, FileNotFoundError, json.JSONDecodeError) as exc:
        print(f"AI journal error: {exc}", file=sys.stderr)
        return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
