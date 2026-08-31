#!/usr/bin/env python3
"""Append-only, hash-chained execution journal for VSN Marketing AI continuity."""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STATE_PATH = ROOT / ".ai" / "state" / "CURRENT-STATE.yaml"
JOURNAL_PATH = ROOT / ".ai" / "state" / "EXECUTION-JOURNAL.jsonl"
JOURNAL_RELATIVE_PATH = ".ai/state/EXECUTION-JOURNAL.jsonl"

ALLOWED_TYPES = {
    "bootstrap_sync",
    "checkpoint",
    "recovery",
    "task_started",
    "task_blocked",
    "task_unblocked",
    "task_transition",
    "architecture_decision",
    "manual_sync",
}


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
    }
    return canonical_hash(payload)


def nonempty_lines(text: str) -> list[str]:
    return [line for line in text.splitlines() if line.strip()]


def read_events() -> list[dict]:
    if not JOURNAL_PATH.exists():
        raise ValueError("missing .ai/state/EXECUTION-JOURNAL.jsonl")
    events: list[dict] = []
    for line_no, raw in enumerate(JOURNAL_PATH.read_text(encoding="utf-8").splitlines(), start=1):
        if not raw.strip():
            continue
        try:
            event = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise ValueError(f"journal line {line_no} is invalid JSON: {exc}") from exc
        events.append(event)
    return events


def validate_append_only_lines(base_lines: list[str], current_lines: list[str]) -> list[str]:
    if len(current_lines) < len(base_lines):
        return [
            f"execution journal was truncated: base has {len(base_lines)} event line(s), "
            f"current has {len(current_lines)}"
        ]
    for index, old_line in enumerate(base_lines):
        if current_lines[index] != old_line:
            return [
                f"execution journal history was rewritten at line {index + 1}; "
                "existing event bytes are immutable and only new lines may be appended"
            ]
    return []


def verify_append_only(base: str) -> list[str]:
    verify = subprocess.run(
        ["git", "rev-parse", "--verify", f"{base}^{{commit}}"],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if verify.returncode != 0:
        return [f"cannot verify journal base commit {base!r}: {verify.stderr.strip()}"]

    proc = subprocess.run(
        ["git", "show", f"{base}:{JOURNAL_RELATIVE_PATH}"],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        return []

    base_lines = nonempty_lines(proc.stdout)
    current_lines = nonempty_lines(JOURNAL_PATH.read_text(encoding="utf-8"))
    return validate_append_only_lines(base_lines, current_lines)


def validate() -> list[str]:
    errors: list[str] = []
    try:
        state = load_json(STATE_PATH)
        events = read_events()
    except (FileNotFoundError, json.JSONDecodeError, ValueError) as exc:
        return [str(exc)]

    if not events:
        return ["execution journal must contain at least one event"]

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
        expected_hash = canonical_hash(material)
        if event.get("hash") != expected_hash:
            print(f"DEBUG journal event {idx} stored={event.get('hash')} expected={expected_hash}", file=sys.stderr)
            print("DEBUG material=" + repr(json.dumps(material, sort_keys=True, separators=(",", ":"), ensure_ascii=False)), file=sys.stderr)
            errors.append(f"journal event {idx}: hash mismatch")
        previous_hash = str(event.get("hash", ""))
        previous_seq = seq if isinstance(seq, int) else previous_seq

    current_fp = state_fingerprint(state)
    last = events[-1]
    if last.get("state_fingerprint") != current_fp:
        errors.append(
            "execution journal is stale relative to CURRENT-STATE.yaml; "
            "run `python tools/ai_journal.py record --type manual_sync --summary \"...\"` "
            "after every state/checkpoint/transition change"
        )
    active_task = state.get("execution", {}).get("active_task")
    if last.get("task_id") != active_task:
        errors.append(
            f"journal last task {last.get('task_id')!r} does not match active task {active_task!r}"
        )
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
    with JOURNAL_PATH.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(json.dumps(event, ensure_ascii=False, separators=(",", ":")) + "\n")
    print(f"Recorded journal event #{seq}: {event_type}")


def status() -> None:
    state = load_json(STATE_PATH)
    events = read_events()
    last = events[-1]
    print(f"EVENTS         {len(events)}")
    print(f"LAST SEQ       {last['seq']}")
    print(f"LAST TYPE      {last['type']}")
    print(f"LAST TASK      {last['task_id']}")
    print(f"CHAIN HEAD     {last['hash']}")
    print(f"STATE MATCH    {last['state_fingerprint'] == state_fingerprint(state)}")


def main() -> int:
    parser = argparse.ArgumentParser(description="VSN Marketing hash-chained AI execution journal")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    sub.add_parser("status")
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
