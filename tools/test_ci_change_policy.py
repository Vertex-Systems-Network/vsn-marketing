#!/usr/bin/env python3
"""Tests for change-aware CI classification."""
from __future__ import annotations

import importlib.util
import json
import os
import subprocess
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


def check_git_change_sets() -> None:
    """Renames and deletions of application code must surface in the change set."""
    with tempfile.TemporaryDirectory() as tmp:
        repo = Path(tmp)

        def run(*args: str) -> str:
            proc = subprocess.run(["git", *args], cwd=repo, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
            return proc.stdout.strip()

        run("init", "-q")
        run("config", "user.email", "ci@example.invalid")
        run("config", "user.name", "ci")
        (repo / "app").mkdir()
        (repo / "docs").mkdir()
        (repo / "app" / "Auth.php").write_text("<?php\n", encoding="utf-8")
        (repo / "app" / "Gate.php").write_text("<?php\n", encoding="utf-8")
        (repo / "docs" / "a.md").write_text("a\n", encoding="utf-8")
        run("add", "-A")
        run("commit", "-q", "-m", "base")
        base = run("rev-parse", "HEAD")

        run("mv", "app/Auth.php", "docs/Auth.php")
        run("rm", "-q", "app/Gate.php")
        (repo / "docs" / "a.md").write_text("b\n", encoding="utf-8")
        run("add", "-A")
        run("commit", "-q", "-m", "move")
        head = run("rev-parse", "HEAD")

        old_root = policy.ROOT
        policy.ROOT = repo
        try:
            paths = policy.changed_paths(base, head)
        finally:
            policy.ROOT = old_root
        assert {"app/Auth.php", "docs/Auth.php", "app/Gate.php", "docs/a.md"} <= set(paths), paths
        result = policy.classify_paths(paths)
        assert result["class"] == "full" and result["application_required"] is True, result


def main() -> int:
    expect([".ai/state/CURRENT-STATE.yaml", "docs/operations/x.md"])
    expect(["README.md", "AGENTS.md"])
    expect(["app/Services/Publishing.php"], app=True, security=True, klass="full")
    expect([".github/workflows/security-ci.yml"], app=True, security=True, klass="full")
    expect(["tools/ci_change_policy.py"], app=True, security=True, klass="full")
    expect(["docs/research.md"], force_full=True, app=True, security=True, klass="full")
    expect([".ai/ai/AGENT-REGISTRY.yaml"], app=True, security=True, klass="full")
    expect([".ai/ai/TOOL-REGISTRY.yaml", "docs/x.md"], app=True, security=True, klass="full")
    expect([".ai/aim/notes.md"])

    check_git_change_sets()

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
