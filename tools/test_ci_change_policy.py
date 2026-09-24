#!/usr/bin/env python3
"""Tests for change-aware CI classification."""
from __future__ import annotations

import importlib.util
import json
import os
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("ci_change_policy", HERE / "ci_change_policy.py")
policy = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(policy)


def expect(paths, *, force_full=False, app=False, security=False, klass="control-only"):
    result = policy.classify_paths(paths, force_full=force_full)
    assert result["application_required"] is app, result
    assert result["security_required"] is security, result
    assert result["class"] == klass, result


def main() -> int:
    expect([".ai/state/CURRENT-STATE.yaml", "docs/operations/x.md"])
    expect(["README.md", "AGENTS.md"])
    expect(["app/Services/Publishing.php"], app=True, security=True, klass="full")
    expect([".github/workflows/security-ci.yml"], app=True, security=True, klass="full")
    expect(["tools/ci_change_policy.py"], app=True, security=True, klass="full")
    expect(["docs/research.md"], force_full=True, app=True, security=True, klass="full")

    empty = policy.classify_paths([])
    assert empty["application_required"] is True and empty["security_required"] is True
    assert empty["reason"] == "empty-or-unresolved-change-set-fails-closed"

    with tempfile.TemporaryDirectory() as tmp:
        event = Path(tmp) / "event.json"
        event.write_text(json.dumps({"pull_request": {"body": "notes\nCI-Mode: full\nWork Done and Submitted"}}), encoding="utf-8")
        old = os.environ.pop("CI_FORCE_FULL", None)
        try:
            assert policy.event_forces_full(str(event)) is True
            event.write_text(json.dumps({"pull_request": {"body": "CI-Mode: fast"}}), encoding="utf-8")
            assert policy.event_forces_full(str(event)) is False
            os.environ["CI_FORCE_FULL"] = "1"
            assert policy.event_forces_full(str(event)) is True
        finally:
            if old is None:
                os.environ.pop("CI_FORCE_FULL", None)
            else:
                os.environ["CI_FORCE_FULL"] = old

    print("ci_change_policy tests: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
