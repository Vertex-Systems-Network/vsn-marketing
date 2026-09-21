#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
from pathlib import Path

HERE=Path(__file__).resolve().parent
SPEC=importlib.util.spec_from_file_location("supervisor_contract", HERE/"supervisor_contract.py")
m=importlib.util.module_from_spec(SPEC); assert SPEC.loader; SPEC.loader.exec_module(m)

assert m.migration_review_errors({"docs/x.md"}, "") == []
errors=m.migration_review_errors({"database/migrations/2026_01_01_test.php"}, "")
assert len(errors)==len(m.MIGRATION_MARKERS)
body="\n".join(m.MIGRATION_MARKERS)
assert m.migration_review_errors({"database/migrations/2026_01_01_test.php"}, body)==[]

assert m.main_observation_class("a"*40, "a"*40, ancestor=True, changed=set()) == "exact"
self_paths={
    ".ai/state/CURRENT-STATE.yaml",
    ".ai/state/LAST-CHECKPOINT.md",
    ".ai/state/EXECUTION-JOURNAL.jsonl",
    ".ai/state/archive/EXECUTION-JOURNAL-0001-0080.jsonl",
    ".ai/coordination/OPEN-WORK-QUEUE.yaml",
    ".ai/runner/RUNNER-BENCHMARK.yaml",
}
assert m.main_observation_class("a"*40, "b"*40, ancestor=True, changed=self_paths) == "self_reconciliation_descendant"
assert m.main_observation_errors("a"*40, "b"*40, ancestor=True, changed=self_paths) == []
assert m.main_observation_class("a"*40, "b"*40, ancestor=True, changed={"app/Services/X.php"}) == "material_drift"
assert any("material protected-main drift" in e for e in m.main_observation_errors("a"*40, "b"*40, ancestor=True, changed={"app/Services/X.php"}))
assert m.main_observation_class("a"*40, "b"*40, ancestor=False, changed=set()) == "conflict"
assert any("not a descendant" in e for e in m.main_observation_errors("a"*40, "b"*40, ancestor=False, changed=set()))
print("supervisor_contract tests: PASS")
