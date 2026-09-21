#!/usr/bin/env python3
"""Classify repository changes for fail-closed, change-aware CI execution."""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORCE_FULL_MARKER = "CI-Mode: full"

CONTROL_EXACT = {
    "README.md",
    "AGENTS.md",
}
CONTROL_PREFIXES = (
    ".ai/",
    "docs/",
)


def normalize_path(path: str) -> str:
    value = path.strip().replace("\\", "/")
    while value.startswith("./"):
        value = value[2:]
    return value.lstrip("/")


def is_control_only_path(path: str) -> bool:
    value = normalize_path(path)
    return value in CONTROL_EXACT or any(value.startswith(prefix) for prefix in CONTROL_PREFIXES)


def classify_paths(paths: list[str], force_full: bool = False) -> dict[str, object]:
    normalized = sorted({normalize_path(path) for path in paths if normalize_path(path)})
    if not normalized:
        return {
            "class": "full",
            "control_only": False,
            "force_full": bool(force_full),
            "application_required": True,
            "security_required": True,
            "changed_files": normalized,
            "reason": "empty-or-unresolved-change-set-fails-closed",
        }
    control_only = all(is_control_only_path(path) for path in normalized)
    full = bool(force_full) or not control_only
    return {
        "class": "full" if full else "control-only",
        "control_only": control_only,
        "force_full": bool(force_full),
        "application_required": full,
        "security_required": full,
        "changed_files": normalized,
        "reason": (
            "explicit-full-ci-marker"
            if force_full
            else "material-or-security-sensitive-change"
            if not control_only
            else "governance-docs-research-only"
        ),
    }


def event_forces_full(path: str | None) -> bool:
    if os.environ.get("CI_FORCE_FULL", "").strip().lower() in {"1", "true", "yes"}:
        return True
    if not path:
        return False
    event_path = Path(path)
    if not event_path.is_file():
        raise ValueError(f"event file is missing: {event_path}")
    try:
        payload = json.loads(event_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"cannot read CI event payload: {exc}") from exc
    body = ((payload.get("pull_request") or {}).get("body") or "")
    return any(line.strip() == FORCE_FULL_MARKER for line in body.splitlines())


def git(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", *args], cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def resolve_base(base: str, head: str) -> str:
    value = (base or "").strip()
    if value and value != "0" * 40:
        check = git("rev-parse", "--verify", f"{value}^{{commit}}")
        if check.returncode == 0:
            return value
    parent = git("rev-parse", "--verify", f"{head}^")
    if parent.returncode == 0:
        return parent.stdout.strip()
    raise ValueError("cannot resolve a safe CI diff base")


def changed_paths(base: str, head: str) -> list[str]:
    resolved = resolve_base(base, head)
    proc = git("diff", "--name-only", "--diff-filter=ACMRTUXB", resolved, head)
    if proc.returncode != 0:
        raise ValueError(f"git diff failed: {proc.stderr.strip()}")
    return [line.strip() for line in proc.stdout.splitlines() if line.strip()]


def emit(result: dict[str, object], output_path: str | None) -> None:
    rows = {
        "change_class": str(result["class"]),
        "control_only": str(bool(result["control_only"])).lower(),
        "force_full": str(bool(result["force_full"])).lower(),
        "application_required": str(bool(result["application_required"])).lower(),
        "security_required": str(bool(result["security_required"])).lower(),
        "reason": str(result["reason"]),
    }
    for key, value in rows.items():
        print(f"{key}={value}")
    print("changed_files=" + json.dumps(result["changed_files"], separators=(",", ":")))
    if output_path:
        with Path(output_path).open("a", encoding="utf-8", newline="\n") as handle:
            for key, value in rows.items():
                handle.write(f"{key}={value}\n")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    classify = sub.add_parser("classify")
    classify.add_argument("--base", required=True)
    classify.add_argument("--head", required=True)
    classify.add_argument("--event-path")
    classify.add_argument("--github-output")
    args = parser.parse_args()
    try:
        if args.command == "classify":
            force = event_forces_full(args.event_path)
            result = classify_paths(changed_paths(args.base, args.head), force_full=force)
            emit(result, args.github_output)
            return 0
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        print(f"CI change policy error: {exc}", file=sys.stderr)
        return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
