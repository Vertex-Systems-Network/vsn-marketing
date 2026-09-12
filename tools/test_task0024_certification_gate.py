#!/usr/bin/env python3
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

TOOLS = Path(__file__).resolve().parent
if str(TOOLS) not in __import__("sys").path:
    __import__("sys").path.insert(0, str(TOOLS))

from delivery_benchmark_evidence import validate_and_aggregate
from task0024_certification_gate import (
    REQUIRED_REPOSITORY_EVIDENCE,
    _tracked_repository_artifact,
    evaluate_certification,
)

ROOT = Path(__file__).resolve().parent.parent
SOURCE_COMMIT = "a" * 40
ACCEPTANCE_HEAD = "b" * 40
RESOLVED_CONTRACT = "All TASK-0023 environment-sensitive thresholds are reviewed and numeric."
COMPLETE_EVIDENCE = set(REQUIRED_REPOSITORY_EVIDENCE)


def valid_evidence(benchmark_id: str = "steady-main") -> dict:
    return {
        "schema_version": 1,
        "benchmark_id": benchmark_id,
        "commit_sha": SOURCE_COMMIT,
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
            "measurement_window_seconds": 2,
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
                    "reconciliation_lag_ms": [50, 60, 70, 80],
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
                    "reconciliation_lag_ms": [55, 65, 75, 85],
                },
            },
        ],
    }


def approved_manifest(evidence: dict) -> dict:
    aggregate = validate_and_aggregate(evidence)
    benchmark_id = aggregate["benchmark_id"]
    return {
        "schema_version": 1,
        "task_id": "TASK-0024",
        "threshold_set_id": "delivery-owner-reviewed-v1",
        "status": "approved",
        "source_commit_sha": SOURCE_COMMIT,
        "approval": {
            "role": "delivery_owner",
            "approved_by": "delivery-owner@example.test",
            "approved_at": "2026-09-12T00:00:00Z",
        },
        "evidence_revisions": {
            benchmark_id: aggregate["evidence_fingerprint"],
        },
        "thresholds": {
            "queue_age_p95_ms": {
                "benchmark_id": benchmark_id,
                "metric": "queue_age_ms",
                "statistic": "p95",
                "operator": "max",
                "threshold": 50,
            },
            "queue_age_p99_ms": {
                "benchmark_id": benchmark_id,
                "metric": "queue_age_ms",
                "statistic": "p99",
                "operator": "max",
                "threshold": 50,
            },
            "end_to_end_p95_ms": {
                "benchmark_id": benchmark_id,
                "metric": "end_to_end_ms",
                "statistic": "p95",
                "operator": "max",
                "threshold": 150,
            },
            "end_to_end_p99_ms": {
                "benchmark_id": benchmark_id,
                "metric": "end_to_end_ms",
                "statistic": "p99",
                "operator": "max",
                "threshold": 150,
            },
            "reconciliation_lag_p95_ms": {
                "benchmark_id": benchmark_id,
                "metric": "reconciliation_lag_ms",
                "statistic": "p95",
                "operator": "max",
                "threshold": 100,
            },
            "reconciliation_lag_p99_ms": {
                "benchmark_id": benchmark_id,
                "metric": "reconciliation_lag_ms",
                "statistic": "p99",
                "operator": "max",
                "threshold": 100,
            },
            "sustainable_throughput_ops_s": {
                "benchmark_id": benchmark_id,
                "metric": "throughput_ops_s",
                "statistic": "min",
                "operator": "min",
                "threshold": 1,
            },
        },
    }


def evaluate(
    evidence: dict,
    manifest: dict | None = None,
    *,
    source_commit_is_ancestor: bool = True,
    slo_contract: str = RESOLVED_CONTRACT,
    repository_evidence: set[str] = COMPLETE_EVIDENCE,
) -> dict:
    return evaluate_certification(
        [evidence],
        manifest if manifest is not None else approved_manifest(evidence),
        SOURCE_COMMIT,
        ACCEPTANCE_HEAD,
        source_commit_is_ancestor,
        slo_contract,
        repository_evidence,
    )


class Task0024CertificationGateTest(unittest.TestCase):
    def test_passes_with_distinct_source_commit_and_descendant_acceptance_head(self) -> None:
        evidence = valid_evidence()
        result = evaluate(evidence)
        self.assertEqual(result["status"], "pass")
        self.assertFalse(result["thresholds_inferred"])
        self.assertEqual(result["benchmark_source_commit_sha"], SOURCE_COMMIT)
        self.assertEqual(result["acceptance_head_sha"], ACCEPTANCE_HEAD)
        self.assertEqual(len(result["checks"]), 7)
        self.assertEqual(result["blockers"], [])

    def test_blocks_when_benchmark_source_is_not_ancestor_of_acceptance_head(self) -> None:
        evidence = valid_evidence()
        result = evaluate(evidence, source_commit_is_ancestor=False)
        self.assertEqual(result["status"], "blocked")
        self.assertIn(
            "benchmark source commit is not an ancestor of final acceptance head",
            result["blockers"],
        )

    def test_blocks_while_canonical_slo_contract_remains_tbd(self) -> None:
        evidence = valid_evidence()
        result = evaluate(evidence, slo_contract="queue_age_p95: TBD_MEASURED")
        self.assertEqual(result["status"], "blocked")
        self.assertIn("TASK-0023 SLO contract still contains TBD_MEASURED", result["blockers"])

    def test_blocks_unapproved_or_tbd_threshold_manifest(self) -> None:
        evidence = valid_evidence()
        manifest = approved_manifest(evidence)
        manifest["status"] = "pending"
        manifest["thresholds"]["queue_age_p95_ms"]["threshold"] = "TBD_MEASURED"
        result = evaluate(evidence, manifest)
        self.assertEqual(result["status"], "blocked")
        self.assertIn("threshold manifest is not explicitly approved", result["blockers"])
        self.assertIn("threshold manifest contains unresolved TBD_MEASURED values", result["blockers"])

    def test_blocks_wrong_source_commit_or_unapproved_evidence_revision(self) -> None:
        evidence = valid_evidence()
        manifest = approved_manifest(evidence)
        manifest["source_commit_sha"] = "c" * 40
        manifest["evidence_revisions"]["steady-main"] = "0" * 64
        result = evaluate(evidence, manifest)
        self.assertEqual(result["status"], "blocked")
        self.assertIn(
            "threshold manifest source_commit_sha does not match benchmark source commit",
            result["blockers"],
        )
        self.assertIn("benchmark steady-main evidence fingerprint is not approved", result["blockers"])

    def test_blocks_missing_required_threshold_and_canonical_mapping_changes(self) -> None:
        evidence = valid_evidence()
        manifest = approved_manifest(evidence)
        del manifest["thresholds"]["end_to_end_p99_ms"]
        manifest["thresholds"]["queue_age_p95_ms"]["statistic"] = "p50"
        result = evaluate(evidence, manifest)
        self.assertEqual(result["status"], "blocked")
        self.assertIn("missing required threshold: end_to_end_p99_ms", result["blockers"])
        self.assertIn(
            "threshold rule does not match canonical metric mapping: queue_age_p95_ms",
            result["blockers"],
        )

    def test_blocks_fault_scenarios_from_supplying_steady_state_acceptance(self) -> None:
        evidence = valid_evidence()
        manifest = approved_manifest(evidence)
        evidence["scenario"]["fault_mode"] = "provider-timeout"
        aggregate = validate_and_aggregate(evidence)
        manifest["evidence_revisions"]["steady-main"] = aggregate["evidence_fingerprint"]
        result = evaluate(evidence, manifest)
        self.assertEqual(result["status"], "blocked")
        self.assertTrue(any("fault scenario cannot supply" in blocker for blocker in result["blockers"]))

    def test_blocks_when_measured_result_violates_approved_threshold(self) -> None:
        evidence = valid_evidence()
        manifest = approved_manifest(evidence)
        manifest["thresholds"]["queue_age_p99_ms"]["threshold"] = 20
        result = evaluate(evidence, manifest)
        self.assertEqual(result["status"], "blocked")
        self.assertIn(
            "measured evidence does not satisfy threshold: queue_age_p99_ms",
            result["blockers"],
        )

    def test_blocks_when_required_repository_evidence_is_missing(self) -> None:
        evidence = valid_evidence()
        incomplete = set(COMPLETE_EVIDENCE)
        incomplete.remove("tests/Feature/Security/Phase04DeliverySecurityCertificationTest.php")
        result = evaluate(evidence, repository_evidence=incomplete)
        self.assertEqual(result["status"], "blocked")
        self.assertIn(
            "missing required repository evidence: tests/Feature/Security/Phase04DeliverySecurityCertificationTest.php",
            result["blockers"],
        )

    def test_rejects_benchmark_evidence_from_a_different_source_commit(self) -> None:
        evidence = valid_evidence()
        evidence["commit_sha"] = "c" * 40
        with self.assertRaisesRegex(ValueError, "does not match benchmark source commit"):
            evaluate(evidence, approved_manifest(valid_evidence()))

    def test_requires_artifacts_to_live_inside_the_repository(self) -> None:
        tracked = _tracked_repository_artifact(
            ROOT,
            Path("tools/test_task0024_certification_gate.py"),
            "test artifact",
        )
        self.assertEqual(tracked, (ROOT / "tools/test_task0024_certification_gate.py").resolve())

        with tempfile.TemporaryDirectory() as tmp:
            outside = Path(tmp) / "evidence.json"
            outside.write_text("{}", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "must be inside the repository"):
                _tracked_repository_artifact(ROOT, outside, "benchmark evidence")


if __name__ == "__main__":
    unittest.main(verbosity=2)
