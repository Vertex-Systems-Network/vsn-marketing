#!/usr/bin/env python3
"""Validate the machine-readable VSN Runner Benchmark registry."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
REGISTRY = ROOT / ".ai" / "runner" / "RUNNER-BENCHMARK.yaml"
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
TERMINAL = {"passed", "failed", "cancelled", "skipped", "completed_terminal"}
EXECUTING = {"running", "executing"} | TERMINAL
NO_AUTH = {"deferred_no_authority", "awaiting_explicit_runtime_authority"}
REQUIRED_TASK_FIELDS = {
    "id", "source", "command_workflow", "exact_source_identity",
    "environment_matrix_input_fixture", "authorization_state",
    "security_critical", "merge_blocking", "expected_runner_time",
    "dedup_key", "status", "immutable_evidence",
}


def load_registry(path: Path = REGISTRY) -> dict[str, Any]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise ValueError(f"missing Runner Benchmark: {path.relative_to(ROOT)}") from exc
    except json.JSONDecodeError as exc:
        raise ValueError(f"invalid Runner Benchmark JSON-compatible YAML: {exc}") from exc
    if not isinstance(data, dict):
        raise ValueError("Runner Benchmark root must be an object")
    return data


def validate_registry(doc: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if doc.get("schema_version") != 1:
        errors.append("Runner Benchmark schema_version must be 1")
    policy = doc.get("policy", {})
    if policy.get("registration_grants_execution_authority") is not False:
        errors.append("Runner registration must never grant execution authority")
    if policy.get("terminal_evidence_required") is not True:
        errors.append("terminal_evidence_required must be true")
    if policy.get("exact_source_identity_required_before_execution") is not True:
        errors.append("exact_source_identity_required_before_execution must be true")

    tasks = doc.get("tasks")
    if not isinstance(tasks, list):
        return errors + ["Runner Benchmark tasks must be a list"]

    ids: set[str] = set()
    dedup: set[str] = set()
    for row in tasks:
        if not isinstance(row, dict):
            errors.append("Runner task must be an object")
            continue
        missing = sorted(REQUIRED_TASK_FIELDS - set(row))
        if missing:
            errors.append(f"Runner task missing fields: {', '.join(missing)}")
            continue
        rid = str(row.get("id", ""))
        if not re.fullmatch(r"RBT-\d{3}", rid):
            errors.append(f"invalid Runner task id {rid!r}")
        if rid in ids:
            errors.append(f"duplicate Runner task id {rid}")
        ids.add(rid)

        key = str(row.get("dedup_key", ""))
        if not key:
            errors.append(f"{rid}: dedup_key is required")
        elif key in dedup:
            errors.append(f"{rid}: duplicate dedup_key {key}")
        dedup.add(key)

        if not isinstance(row.get("security_critical"), bool):
            errors.append(f"{rid}: security_critical must be boolean")
        if not isinstance(row.get("merge_blocking"), bool):
            errors.append(f"{rid}: merge_blocking must be boolean")
        if not str(row.get("expected_runner_time", "")).strip():
            errors.append(f"{rid}: expected_runner_time must be recorded")
        if not str(row.get("environment_matrix_input_fixture", "")).strip():
            errors.append(f"{rid}: environment/matrix/input/fixture identity is required")

        source = row.get("exact_source_identity")
        if not isinstance(source, dict) or "git_sha" not in source:
            errors.append(f"{rid}: exact_source_identity.git_sha field is required")
            continue
        status = str(row.get("status", ""))
        auth = str(row.get("authorization_state", ""))
        sha = source.get("git_sha")
        if status in EXECUTING:
            if not isinstance(sha, str) or not SHA_RE.fullmatch(sha):
                errors.append(f"{rid}: executing/terminal work requires exact 40-char source SHA")
            if auth in NO_AUTH:
                errors.append(f"{rid}: work cannot execute without current authority")
        elif sha is None and source.get("resolution_required_before_execution") is not True:
            errors.append(f"{rid}: unresolved source identity must be required before execution")

        if status in TERMINAL and not row.get("immutable_evidence"):
            errors.append(f"{rid}: terminal Runner work requires immutable evidence")

    return errors


def validate() -> list[str]:
    try:
        return validate_registry(load_registry())
    except ValueError as exc:
        return [str(exc)]


def status() -> None:
    doc = load_registry()
    tasks = doc.get("tasks", [])
    print(f"RUNNER TASKS   {len(tasks)}")
    for row in tasks:
        print(f"- {row.get('id')}: status={row.get('status')} auth={row.get('authorization_state')} merge_blocking={row.get('merge_blocking')}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["validate", "status"])
    args = parser.parse_args()
    try:
        if args.command == "validate":
            errors = validate()
            if errors:
                print("Runner Benchmark validation FAILED:", file=sys.stderr)
                for error in errors:
                    print(f"- {error}", file=sys.stderr)
                return 1
            print("Runner Benchmark validation PASSED")
            return 0
        errors = validate()
        if errors:
            for error in errors:
                print(f"ERROR: {error}", file=sys.stderr)
            return 1
        status()
        return 0
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError) as exc:
        print(f"Runner Benchmark error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
