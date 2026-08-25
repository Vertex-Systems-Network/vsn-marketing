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
        "execution": {
            "active_task": "TASK-0001",
            "current_phase": "PHASE-00",
            "next_task": "TASK-0002",
        },
        "exact_next_action": "continue deterministically",
    }
    for rel in ctx.BASE_FILES:
        write(root / rel, "{}\n" if rel.endswith(".yaml") else "fixture\n")
    write(root / ".ai/state/CURRENT-STATE.yaml", json.dumps(state))
    write(root / ".ai/tasks/TASK-0001.yaml", '{"id":"TASK-0001"}\n')
    write(root / ".ai/roadmap/PHASE-00.md", "# phase\n")
    write(root / ".ai/research/PHASE-00/TASK-0001-RESEARCH.md", "# active research\n")
    write(root / ".ai/adr/ADR-0001-stack.md", "# canonical stack ADR\n")
    write(root / ".ai/adr/ADR-0002-research.md", "# canonical research ADR\n")
    write(root / ".ai/decisions/ADR-0001.md", "# legacy continuity ADR\n")
    write(root / ".ai/decisions/ADR-TEMPLATE.md", "# template must be ignored\n")
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
        assert first["manifest_sha256"] == second["manifest_sha256"], (
            "same repo state must produce same manifest hash"
        )
        assert first["sources"] == second["sources"], (
            "source ordering must be deterministic"
        )

        paths = [item["path"] for item in first["sources"]]
        required = {
            ".ai/02-MODULE-REGISTRY.md",
            ".ai/04-INTEGRATION-STANDARD.md",
            ".ai/11-RESEARCH-FIRST-STANDARD.md",
            ".ai/12-QUALITY-ENGINEERING-GATES.md",
            ".ai/state/BLOCKERS.md",
            ".ai/roadmap/PREPLANNED-IMPLEMENTATION-PLAN.md",
            ".ai/integrations/CAPABILITY-MATRIX.yaml",
            ".ai/integrations/PROVIDER-CATALOG.yaml",
            ".ai/tasks/TASK-0001.yaml",
            ".ai/roadmap/PHASE-00.md",
            ".ai/research/PHASE-00/TASK-0001-RESEARCH.md",
            ".ai/adr/ADR-0001-stack.md",
            ".ai/adr/ADR-0002-research.md",
            ".ai/decisions/ADR-0001.md",
            ".ai/contracts/EXTRA.md",
        }
        assert required.issubset(paths), "canonical governance/context sources must be present"
        assert ".ai/decisions/ADR-TEMPLATE.md" not in paths, "ADR template must not be execution context"
        assert paths.index(".ai/adr/ADR-0001-stack.md") < paths.index(
            ".ai/decisions/ADR-0001.md"
        ), "canonical ADR namespace must precede legacy decision records"

        manifest = root / "manifest.json"
        manifest.write_text(json.dumps(first), encoding="utf-8")
        assert ctx.verify_manifest(manifest) == [], "fresh manifest must verify"

        write(root / ".ai/05-AI-RULES.md", "changed\n")
        errors = ctx.verify_manifest(manifest)
        assert errors, "changed context source must make prior manifest stale"
        refreshed = ctx.build_pack(include_content=False)
        assert refreshed["manifest_sha256"] != first["manifest_sha256"], (
            "source change must alter manifest hash"
        )

        full = ctx.build_pack(include_content=True)
        assert all("content" in item for item in full["sources"]), (
            "full pack must include source contents"
        )

        (root / ".ai/research/PHASE-00/TASK-0001-RESEARCH.md").unlink()
        without_optional_research = ctx.build_pack(include_content=False)
        assert ".ai/research/PHASE-00/TASK-0001-RESEARCH.md" not in [
            item["path"] for item in without_optional_research["sources"]
        ], "research pack is included when present but must remain optional for non-research tasks"

    print(
        "ai_context integrity tests: PASS "
        "(determinism, canonical sources, ADR namespaces, research, verification, drift, full pack)"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
