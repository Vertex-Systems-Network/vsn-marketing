#!/usr/bin/env python3
"""Negative/positive tests for VSN Marketing AI policy registry validation."""
from __future__ import annotations

import copy
import importlib.util
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("ai_policy", HERE / "ai_policy.py")
policy = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(policy)


def expect_error(docs, needle: str) -> None:
    errors = policy.validate_documents(docs)
    assert any(needle in error for error in errors), f"expected error containing {needle!r}; got {errors}"


def main() -> int:
    original = policy.load_documents()
    assert policy.validate_documents(original) == [], "repository AI policy registries must be valid"

    docs = copy.deepcopy(original)
    docs["agents"]["agents"][0]["tools"].append("missing_tool")
    expect_error(docs, "unknown tool")

    docs = copy.deepcopy(original)
    connector = next(row for row in docs["agents"]["agents"] if row["id"] == "connector_engineer")
    connector["max_autonomy"] = "R0"
    expect_error(docs, "above agent maximum")

    docs = copy.deepcopy(original)
    docs["prompts"]["prompts"][0]["required_eval_suite"] = "missing_eval"
    expect_error(docs, "unknown required eval suite")

    docs = copy.deepcopy(original)
    docs["autonomy"]["tiers"]["R3"]["automatic"] = True
    expect_error(docs, "R3 must never be automatic")

    docs = copy.deepcopy(original)
    docs["agents"]["agents"][0]["memory_scopes"].append("other_workspace")
    expect_error(docs, "unknown memory scope")

    docs = copy.deepcopy(original)
    docs["tools"]["tools"].append(copy.deepcopy(docs["tools"]["tools"][0]))
    expect_error(docs, "duplicate tool id")

    print("ai_policy tests: PASS (registry validity + six negative guards)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
