#!/usr/bin/env python3
"""Zero-dependency tests for the VSN Marketing AI continuity control plane."""
from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JOURNAL_TOOL = ROOT / "tools" / "ai_journal.py"


def load_journal_module():
    spec = importlib.util.spec_from_file_location("vsn_ai_journal_under_test", JOURNAL_TOOL)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load ai_journal.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class JournalIntegrityTests(unittest.TestCase):
    def setUp(self):
        self.module = load_journal_module()
        self.temp = tempfile.TemporaryDirectory()
        root = Path(self.temp.name)
        self.state_path = root / "CURRENT-STATE.yaml"
        self.journal_path = root / "EXECUTION-JOURNAL.jsonl"
        self.module.STATE_PATH = self.state_path
        self.module.JOURNAL_PATH = self.journal_path
        self.state = {
            "schema_version": 1,
            "execution": {
                "status": "in_progress",
                "current_phase": "PHASE-00",
                "active_task": "TASK-0001",
                "last_completed_task": None,
                "next_task": "TASK-0002",
            },
            "progress": {"roadmap_percent": 0.0, "phase_percent": 0.0},
            "blockers": [],
            "exact_next_action": "Continue deterministic work.",
        }
        self.state_path.write_text(json.dumps(self.state), encoding="utf-8")
        self._write_valid_event()

    def tearDown(self):
        self.temp.cleanup()

    def _write_valid_event(self):
        material = {
            "seq": 1,
            "timestamp": "2026-08-22T23:00:00+00:00",
            "type": "bootstrap_sync",
            "task_id": "TASK-0001",
            "state_fingerprint": self.module.state_fingerprint(self.state),
            "summary": "fixture",
            "prev_hash": "GENESIS",
        }
        event = dict(material)
        event["hash"] = self.module.canonical_hash(material)
        self.journal_path.write_text(
            json.dumps(event, separators=(",", ":")) + "\n",
            encoding="utf-8",
        )

    def test_valid_chain_matches_state(self):
        self.assertEqual([], self.module.validate())

    def test_tampered_event_is_detected(self):
        event = json.loads(self.journal_path.read_text(encoding="utf-8"))
        event["summary"] = "tampered after hashing"
        self.journal_path.write_text(json.dumps(event) + "\n", encoding="utf-8")
        errors = self.module.validate()
        self.assertTrue(any("hash mismatch" in error for error in errors), errors)

    def test_stale_state_fingerprint_is_detected(self):
        self.state["exact_next_action"] = "Changed without journal synchronization."
        self.state_path.write_text(json.dumps(self.state), encoding="utf-8")
        errors = self.module.validate()
        self.assertTrue(any("journal is stale" in error for error in errors), errors)

    def test_active_task_mismatch_is_detected(self):
        self.state["execution"]["active_task"] = "TASK-9999"
        self.state_path.write_text(json.dumps(self.state), encoding="utf-8")
        errors = self.module.validate()
        self.assertTrue(any("does not match active task" in error for error in errors), errors)


class RepositoryContinuityTests(unittest.TestCase):
    def test_repository_journal_validator_passes(self):
        proc = subprocess.run(
            [sys.executable, str(ROOT / "tools" / "ai_journal.py"), "validate"],
            cwd=ROOT,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
