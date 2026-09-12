#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from delivery_benchmark_evidence import validate_and_aggregate

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "tools" / "delivery_benchmark_evidence.py"


def valid_evidence() -> dict:
    return {
        "schema_version": 1,
        "benchmark_id": "steady-main",
        "commit_sha": "a" * 40,
        "environment": {
            "php_version": "8.5.10",
            "laravel_version": "13.26.1",
            "postgresql_version": "18.6",
            "redis_version": "8.10.1",
            "runner": {"os": "ubuntu-24.04", "cpu_count": 4, "memory_mb": 8192},
        },
        "scenario": {
            "name": "steady",
            "fault_mode": "none",
            "seed": 20260912,
            "operation_count": 4,
            "concurrency": 2,
            "warmup_seconds": 5,
            "measurement_window_seconds": 10,
        },
        "runs": [
            {
                "run_id": "run-1",
                "warmup_observations_excluded": True,
                "elapsed_seconds": 2,
                "completed_operations": 4,
                "observations": {
                    "queue_age_ms": [10, 20, 30, 40],
                    "end_to_end_ms": [100, 110, 120, 130],
                },
            },
            {
                "run_id": "run-2",
                "warmup_observations_excluded": True,
                "elapsed_seconds": 4,
                "completed_operations": 4,
                "observations": {
                    "queue_age_ms": [15, 25, 35, 45],
                    "end_to_end_ms": [105, 115, 125, 135],
                },
            },
        ],
    }


class DeliveryBenchmarkEvidenceTest(unittest.TestCase):
    def test_validates_repeated_raw_evidence_without_making_acceptance_decision(self) -> None:
        result = validate_and_aggregate(valid_evidence())
        self.assertEqual(result["status"], "evidence_valid")
        self.assertEqual(result["certification_decision"], "not_evaluated")
        self.assertFalse(result["thresholds_inferred"])
        self.assertEqual(result["run_count"], 2)
        self.assertEqual(result["runs"][0]["metrics"]["queue_age_ms"]["p95"], 40.0)
        self.assertEqual(result["pooled_metrics"]["queue_age_ms"]["count"], 8)
        self.assertEqual(result["pooled_metrics"]["queue_age_ms"]["p50"], 25.0)
        self.assertEqual(result["throughput_ops_s"]["per_run"], [2.0, 1.0])
        self.assertAlmostEqual(result["throughput_ops_s"]["pooled"], 8 / 6)

    def test_requires_at_least_two_runs_and_separates_warmup(self) -> None:
        evidence = valid_evidence()
        evidence["runs"] = evidence["runs"][:1]
        with self.assertRaisesRegex(ValueError, "at least two repeated"):
            validate_and_aggregate(evidence)

        evidence = valid_evidence()
        evidence["runs"][0]["warmup_observations_excluded"] = False
        with self.assertRaisesRegex(ValueError, "warmup_observations_excluded"):
            validate_and_aggregate(evidence)

    def test_rejects_mismatched_metrics_and_invalid_samples(self) -> None:
        evidence = valid_evidence()
        del evidence["runs"][1]["observations"]["end_to_end_ms"]
        with self.assertRaisesRegex(ValueError, "same observation metrics"):
            validate_and_aggregate(evidence)

        for invalid in (True, -1, float("nan"), float("inf")):
            with self.subTest(invalid=invalid):
                evidence = valid_evidence()
                evidence["runs"][0]["observations"]["queue_age_ms"][0] = invalid
                with self.assertRaises(ValueError):
                    validate_and_aggregate(evidence)

    def test_rejects_missing_environment_identity_and_sensitive_material(self) -> None:
        evidence = valid_evidence()
        evidence["environment"]["runner"]["cpu_count"] = 0
        with self.assertRaisesRegex(ValueError, "cpu_count"):
            validate_and_aggregate(evidence)

        evidence = valid_evidence()
        evidence["runs"][0]["recipient_email"] = "person@example.test"
        with self.assertRaisesRegex(ValueError, "forbidden sensitive key"):
            validate_and_aggregate(evidence)

    def test_rejects_completed_count_above_declared_operation_count(self) -> None:
        evidence = valid_evidence()
        evidence["runs"][0]["completed_operations"] = 5
        with self.assertRaisesRegex(ValueError, "cannot exceed"):
            validate_and_aggregate(evidence)

    def test_cli_distinguishes_valid_from_invalid_evidence(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "evidence.json"
            path.write_text(json.dumps(valid_evidence()), encoding="utf-8")
            valid = subprocess.run(
                [sys.executable, str(SCRIPT), "--input", str(path)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
            self.assertEqual(valid.returncode, 0)
            self.assertEqual(json.loads(valid.stdout)["status"], "evidence_valid")

            invalid_payload = valid_evidence()
            invalid_payload["runs"] = invalid_payload["runs"][:1]
            path.write_text(json.dumps(invalid_payload), encoding="utf-8")
            invalid = subprocess.run(
                [sys.executable, str(SCRIPT), "--input", str(path)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
            self.assertEqual(invalid.returncode, 2)
            self.assertEqual(json.loads(invalid.stdout)["status"], "error")


if __name__ == "__main__":
    unittest.main(verbosity=2)
