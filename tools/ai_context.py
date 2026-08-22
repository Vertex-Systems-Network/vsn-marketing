#!/usr/bin/env python3
"""Deterministic context-pack compiler for VSN Marketing AI continuity."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AI = ROOT / ".ai"
STATE = AI / "state" / "CURRENT-STATE.yaml"
CHECKPOINT = AI / "state" / "LAST-CHECKPOINT.md"
INDEX = AI / "tasks" / "INDEX.yaml"
ROADMAP = AI / "roadmap" / "ROADMAP.yaml"

BASE_FILES = [
    "AGENTS.md", ".ai/00-PROJECT-CHARTER.md", ".ai/01-MASTER-ARCHITECTURE.md",
    ".ai/05-AI-RULES.md", ".ai/10-AI-CONTROL-PLANE.md",
    ".ai/state/CURRENT-STATE.yaml", ".ai/state/LAST-CHECKPOINT.md",
    ".ai/state/TEST-STATE.yaml", ".ai/state/EXECUTION-JOURNAL.jsonl",
    ".ai/tasks/INDEX.yaml", ".ai/roadmap/ROADMAP.yaml",
    ".ai/ai/AGENT-REGISTRY.yaml", ".ai/ai/AUTONOMY-POLICY.yaml",
    ".ai/ai/MODEL-CAPABILITY-REGISTRY.yaml", ".ai/ai/PROMPT-REGISTRY.yaml",
    ".ai/ai/TOOL-REGISTRY.yaml", ".ai/ai/EVAL-REGISTRY.yaml",
    ".ai/ai/MEMORY-POLICY.yaml", ".ai/ai/AI-OBSERVABILITY-SCHEMA.yaml",
    ".ai/contracts/AI-EXECUTION.md", ".ai/contracts/AI-GATEWAY.md",
    ".ai/contracts/AI-CONTEXT-PACK.md",
]


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def ordered_sources() -> list[str]:
    state = load_json(STATE)
    active_task = state["execution"]["active_task"]
    files = list(BASE_FILES)
    files.append(f".ai/tasks/{active_task}.yaml")
    phase = state["execution"]["current_phase"]
    phase_doc = f".ai/roadmap/{phase}.md"
    if (ROOT / phase_doc).exists():
        files.append(phase_doc)
    files.extend(str(path.relative_to(ROOT)).replace("\\", "/") for path in sorted((AI / "decisions").glob("ADR-*.md")) if path.name != "ADR-TEMPLATE.md")
    files.extend(str(path.relative_to(ROOT)).replace("\\", "/") for path in sorted((AI / "contracts").glob("*.md")) if str(path.relative_to(ROOT)).replace("\\", "/") not in files)
    return list(dict.fromkeys(files))


def build_pack(include_content: bool) -> dict:
    state = load_json(STATE)
    sources = []
    for rel in ordered_sources():
        path = ROOT / rel
        if not path.exists():
            raise ValueError(f"required context source missing: {rel}")
        text = path.read_text(encoding="utf-8")
        item = {"path": rel, "sha256": sha256_text(text), "bytes": len(text.encode("utf-8"))}
        if include_content:
            item["content"] = text
        sources.append(item)
    basis = {
        "schema_version": 1,
        "project": state["project"]["name"],
        "active_task": state["execution"]["active_task"],
        "current_phase": state["execution"]["current_phase"],
        "next_task": state["execution"].get("next_task"),
        "exact_next_action": state["exact_next_action"],
        "sources": [{k: v for k, v in item.items() if k != "content"} for item in sources],
    }
    manifest_hash = sha256_text(json.dumps(basis, sort_keys=True, separators=(",", ":"), ensure_ascii=False))
    return {**basis, "manifest_sha256": manifest_hash, "sources": sources}


def verify_manifest(path: Path) -> list[str]:
    expected = build_pack(False)
    actual = json.loads(path.read_text(encoding="utf-8"))
    errors = []
    if actual.get("manifest_sha256") != expected["manifest_sha256"]:
        errors.append("context manifest hash does not match current repository state")
    if actual.get("active_task") != expected["active_task"]:
        errors.append("context manifest active_task is stale")
    if [(x.get("path"), x.get("sha256")) for x in actual.get("sources", [])] != [(x.get("path"), x.get("sha256")) for x in expected["sources"]]:
        errors.append("context manifest source order or checksums are stale")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description="VSN Marketing deterministic AI context compiler")
    sub = parser.add_subparsers(dest="command", required=True)
    manifest = sub.add_parser("manifest"); manifest.add_argument("--out")
    build = sub.add_parser("build"); build.add_argument("--out", required=True)
    verify = sub.add_parser("verify"); verify.add_argument("--file", required=True)
    args = parser.parse_args()
    try:
        if args.command == "manifest":
            payload = build_pack(False); text = json.dumps(payload, indent=2, ensure_ascii=False) + "\n"
            Path(args.out).write_text(text, encoding="utf-8") if args.out else print(text, end="")
            return 0
        if args.command == "build":
            Path(args.out).write_text(json.dumps(build_pack(True), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            print(f"Context pack written: {args.out}"); return 0
        errors = verify_manifest(Path(args.file))
        if errors:
            [print(f"ERROR: {error}") for error in errors]; return 1
        print("Context manifest matches current repository state."); return 0
    except (ValueError, KeyError, json.JSONDecodeError, OSError) as exc:
        print(f"ERROR: {exc}"); return 1


if __name__ == "__main__":
    raise SystemExit(main())
