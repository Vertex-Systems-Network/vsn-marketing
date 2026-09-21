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
print("supervisor_contract tests: PASS")
