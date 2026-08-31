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

    def test_append_only_accepts_exact_prefix_plus_new_events(self):
        base = ['{"seq":1}', '{"seq":2}']
        current = base + ['{"seq":3}']
        self.assertEqual([], self.module.validate_append_only_lines(base, current))

    def test_append_only_rejects_rewritten_history(self):
        base = ['{"seq":1}', '{"seq":2}']
        current = ['{"seq":1}', '{"seq":2,"rewritten":true}', '{"seq":3}']
        errors = self.module.validate_append_only_lines(base, current)
        self.assertTrue(any("rewritten" in error for error in errors), errors)

    def test_append_only_rejects_truncation(self):
        base = ['{"seq":1}', '{"seq":2}']
        current = ['{"seq":1}']
        errors = self.module.validate_append_only_lines(base, current)
        self.assertTrue(any("truncated" in error for error in errors), errors)


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

    def test_task_0015_transition_hash_preview(self):
        module = load_journal_module()
        target_state = {
            "schema_version": 1,
            "execution": {
                "status": "ready",
                "current_phase": "PHASE-03",
                "active_task": "TASK-0016",
                "last_completed_task": "TASK-0015",
                "next_task": "TASK-0017",
            },
            "progress": {
                "roadmap_percent": 21.5,
                "phase_percent": 50.0,
                "calculation": "Calculated deterministically from task weights and completed task statuses.",
            },
            "blockers": [],
            "exact_next_action": "Revalidate current webhook/rate/version behavior, then implement provider-neutral adapter/error/quota/webhook/reconciliation contracts and negative contract tests before adding reference connectors.",
        }
        fingerprint = module.state_fingerprint(target_state)
        evidence = (
            "Protected main 1a5b3791ff5cae2a2aeaffff22eef7cbc48fd1ae passed AI Continuity run 33405400554, "
            "Application Foundation run 33405400523 (foundation, php-floor, integration, e2e), Security Supply Chain run "
            "33405400544 including security-gates, Release Integrity run 33405400493 with signed build provenance and signed "
            "SBOM attestation, and OpenSSF Scorecard run 33405400500. TASK-0015 provider foundation implements workspace-safe "
            "Provider, ProviderConnection, ProviderCapability and ProviderQuota concepts, separate readiness/support states, "
            "secret-reference authentication metadata, multidimensional dynamic quota provenance, fail-closed cross-workspace "
            "tests, rollback-safe migrations, and no concrete provider SDK dependency. Hosted main ruleset 21212844 remains "
            "strict with governance, foundation, php-floor, integration, e2e and security-gates and no bypass actors."
        )
        material = {
            "seq": 43,
            "timestamp": "2026-08-31T15:00:00+00:00",
            "type": "task_transition",
            "task_id": "TASK-0016",
            "state_fingerprint": fingerprint,
            "summary": f"TASK-0015 completed; TASK-0016 activated. {evidence}",
            "prev_hash": "f9a5df9797eaddf7c041ff18409c1c00636cbee77a0e2e953b763a5bd28c2d12",
        }
        print(f"TASK0015_TRANSITION_STATE_FINGERPRINT={fingerprint}")
        print(f"TASK0015_TRANSITION_EVENT_HASH={module.canonical_hash(material)}")


if __name__ == "__main__":
    unittest.main(verbosity=2)
