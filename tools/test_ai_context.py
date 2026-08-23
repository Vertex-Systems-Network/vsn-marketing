#!/usr/bin/env python3
"""Zero-dependency tests for deterministic VSN Marketing AI context packs."""
from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("ai_context", HERE / "ai_context.py")
ctx = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(ctx)


def write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def fixture(root: Path) -> None:
    state = {
        "project": {"name": "VSN Marketing"},
        "execution": {"active_task": "TASK-0001", "current_phase": "PHASE-00", "next_task": "TASK-0002"},
        "exact_next_action": "continue deterministically",
    }
    for rel in ctx.BASE_FILES:
        write(root / rel, "{}\n" if rel.endswith(".yaml") else "fixture\n")
    write(root / ".ai/state/CURRENT-STATE.yaml", json.dumps(state))
    write(root / ".ai/tasks/TASK-0001.yaml", '{"id":"TASK-0001"}\n')
    write(root / ".ai/roadmap/PHASE-00.md", "# phase\n")
    write(root / ".ai/decisions/ADR-0001.md", "# adr\n")
    write(root / ".ai/contracts/EXTRA.md", "# extra\n")


def bind(root: Path) -> None:
    ctx.ROOT = root
    ctx.AI = root / ".ai"
    ctx.STATE = ctx.AI / "state" / "CURRENT-STATE.yaml"
    ctx.CHECKPOINT = ctx.AI / "state" / "LAST-CHECKPOINT.md"
    ctx.INDEX = ctx.AI / "tasks" / "INDEX.yaml"
    ctx.ROADMAP = ctx.AI / "roadmap" / "ROADMAP.yaml"


def main() -> int:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        fixture(root)
        bind(root)
        first = ctx.build_pack(include_content=False)
        second = ctx.build_pack(include_content=False)
        assert first["manifest_sha256"] == second["manifest_sha256"], "same repo state must produce same manifest hash"
        assert first["sources"] == second["sources"], "source ordering must be deterministic"
        manifest = root / "manifest.json"
        manifest.write_text(json.dumps(first), encoding="utf-8")
        assert ctx.verify_manifest(manifest) == [], "fresh manifest must verify"
        write(root / ".ai/05-AI-RULES.md", "changed\n")
        errors = ctx.verify_manifest(manifest)
        assert errors, "changed context source must make prior manifest stale"
        refreshed = ctx.build_pack(include_content=False)
        assert refreshed["manifest_sha256"] != first["manifest_sha256"], "source change must alter manifest hash"
        full = ctx.build_pack(include_content=True)
        assert all("content" in item for item in full["sources"]), "full pack must include source contents"
    print("ai_context integrity tests: PASS (determinism, verification, drift, full pack)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
