#!/usr/bin/env python3
"""Fail closed when GitHub workflow dependencies are not immutable.

TASK-0014 requires every external ``uses:`` reference in repository workflows to
be pinned to an immutable full commit SHA. Local actions are trusted as part of
the reviewed repository tree. Docker action references, if introduced, must use
an immutable sha256 digest.

The scanner intentionally accepts only a canonical block-style ``uses`` key.
Alternative YAML spellings (quoted keys, whitespace before the colon, anchors,
flow mappings, folded scalars, and similar constructs) are either normalized by
the narrow key matcher below or rejected as unsupported. This keeps the guard
fail-closed without introducing a second YAML parser into privileged CI.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

FULL_COMMIT_SHA = re.compile(r"^[0-9a-fA-F]{40}$")
DOCKER_DIGEST = re.compile(r"^docker://[^\s]+@sha256:[0-9a-fA-F]{64}$")
USES_LINE = re.compile(r"^\s*(?:-\s*)?(?:uses|'uses'|\"uses\")\s*:\s*(.+?)\s*$")
USES_TOKEN = re.compile(r"(?:^|[\s{,])(?:uses|'uses'|\"uses\")\s*:")


def iter_workflows(root: Path) -> list[Path]:
    workflow_dir = root / ".github" / "workflows"
    if not workflow_dir.exists():
        return []
    return sorted(
        path
        for path in workflow_dir.rglob("*")
        if path.is_file() and path.suffix.lower() in {".yml", ".yaml"}
    )


def normalize_yaml_scalar(raw: str) -> str:
    # Workflow action references do not legitimately need an inline '#'. Keeping
    # comments out of the parsed scalar makes reviewer-friendly ``# vN`` notes
    # possible without weakening the immutable reference check.
    value = raw.split("#", 1)[0].strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
        value = value[1:-1].strip()
    return value


def validate_reference(reference: str) -> str | None:
    if reference.startswith("./"):
        return None

    if reference.startswith("docker://"):
        if DOCKER_DIGEST.fullmatch(reference):
            return None
        return "docker action must be pinned to an immutable sha256 digest"

    if "@" not in reference:
        return "external action/reusable workflow is missing an @<commit-sha> reference"

    _, ref = reference.rsplit("@", 1)
    if not FULL_COMMIT_SHA.fullmatch(ref):
        return "external action/reusable workflow must use a 40-character commit SHA"

    return None


def scan_file(path: Path) -> list[str]:
    failures: list[str] = []
    for line_number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        stripped = line.lstrip()
        if stripped.startswith("#"):
            continue

        match = USES_LINE.match(line)
        if match is None:
            # Flow-style or otherwise noncanonical uses syntax can evade a
            # line-oriented scalar parser. Reject it rather than silently skip it.
            if USES_TOKEN.search(line):
                failures.append(
                    f"{path}:{line_number}: unsupported/noncanonical uses syntax; "
                    "use a dedicated block-style `uses: owner/repo@<full-sha>` line"
                )
            continue

        reference = normalize_yaml_scalar(match.group(1))
        error = validate_reference(reference)
        if error:
            failures.append(f"{path}:{line_number}: {error}: {reference}")
    return failures


def validate_repository(root: Path) -> list[str]:
    failures: list[str] = []
    workflows = iter_workflows(root)
    if not workflows:
        return [f"{root}: no GitHub workflow files found"]
    for workflow in workflows:
        failures.extend(scan_file(workflow))
    return failures


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()

    failures = validate_repository(args.root.resolve())
    if failures:
        print("GitHub Actions integrity check FAILED:", file=sys.stderr)
        for failure in failures:
            print(f"- {failure}", file=sys.stderr)
        return 1

    print("GitHub Actions integrity check PASS: every external uses: reference is immutable.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
