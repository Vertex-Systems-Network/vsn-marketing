#!/usr/bin/env python3
from __future__ import annotations
import copy
import importlib.util
from pathlib import Path

HERE=Path(__file__).resolve().parent
SPEC=importlib.util.spec_from_file_location("runner_benchmark", HERE/"runner_benchmark.py")
m=importlib.util.module_from_spec(SPEC); assert SPEC.loader; SPEC.loader.exec_module(m)

doc=m.load_registry()
assert m.validate_registry(doc)==[]

bad=copy.deepcopy(doc); bad["policy"]["registration_grants_execution_authority"]=True
assert any("never grant" in e for e in m.validate_registry(bad))

bad=copy.deepcopy(doc); bad["tasks"][1]["dedup_key"]=bad["tasks"][0]["dedup_key"]
assert any("duplicate dedup_key" in e for e in m.validate_registry(bad))

bad=copy.deepcopy(doc); bad["tasks"][3]["status"]="running"
assert any("exact 40-char source SHA" in e or "without current authority" in e for e in m.validate_registry(bad))

bad=copy.deepcopy(doc); bad["tasks"][0]["status"]="passed"; bad["tasks"][0]["exact_source_identity"]["git_sha"]="a"*40
assert any("immutable evidence" in e for e in m.validate_registry(bad))

print("runner_benchmark tests: PASS")
