#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from delivery_slo_gate import evaluate, evaluate_metric

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "tools" / "delivery_slo_gate.py"


class DeliverySloGateTest(unittest.TestCase):
    def test_max_and_min_rules(self) -> None:
        results = evaluate(
            {"queue_age_p95_ms": 120.0, "throughput_ops_s": 55},
            {
                "queue_age_p95_ms": {"operator": "max", "threshold": 150},
                "throughput_ops_s": {"operator": "min", "threshold": 50},
            },
        )
        self.assertTrue(all(result.passed for result in results))
        self.assertEqual([result.metric for result in results], ["queue_age_p95_ms", "throughput_ops_s"])

    def test_failed_threshold_is_reported_without_becoming_an_error(self) -> None:
        [result] = evaluate(
            {"retry_amplification": 1.4},
            {"retry_amplification": {"operator": "max", "threshold": 1.2}},
        )
        self.assertFalse(result.passed)
        self.assertEqual(result.actual, 1.4)

    def test_fails_closed_on_missing_malformed_or_non_finite_evidence(self) -> None:
        with self.assertRaisesRegex(ValueError, "threshold set must not be empty"):
            evaluate({}, {})
        with self.assertRaisesRegex(ValueError, "missing required measurement"):
            evaluate({}, {"queue_age_p95_ms": {"operator": "max", "threshold": 100}})
        with self.assertRaisesRegex(ValueError, "must be an object"):
            evaluate({"queue_age_p95_ms": 10}, {"queue_age_p95_ms": 100})  # type: ignore[arg-type]
        with self.assertRaisesRegex(ValueError, "unsupported operator"):
            evaluate_metric("queue_age_p95_ms", 10, {"operator": "eq", "threshold": 10})
        for value in (True, float("nan"), float("inf"), -float("inf")):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    evaluate_metric("queue_age_p95_ms", value, {"operator": "max", "threshold": 100})
        for threshold in (False, float("nan"), float("inf"), -float("inf")):
            with self.subTest(threshold=threshold):
                with self.assertRaises(ValueError):
                    evaluate_metric("queue_age_p95_ms", 10, {"operator": "max", "threshold": threshold})

    def test_cli_exit_codes_distinguish_pass_fail_and_invalid_evidence(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            measurements = root / "measurements.json"
            thresholds = root / "thresholds.json"
            thresholds.write_text(
                json.dumps({"latency": {"operator": "max", "threshold": 100}}),
                encoding="utf-8",
            )

            measurements.write_text(json.dumps({"latency": 90}), encoding="utf-8")
            passed = subprocess.run(
                [sys.executable, str(SCRIPT), "--measurements", str(measurements), "--thresholds", str(thresholds)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
            self.assertEqual(passed.returncode, 0)
            self.assertEqual(json.loads(passed.stdout)["status"], "pass")

            measurements.write_text(json.dumps({"latency": 110}), encoding="utf-8")
            failed = subprocess.run(
                [sys.executable, str(SCRIPT), "--measurements", str(measurements), "--thresholds", str(thresholds)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
            self.assertEqual(failed.returncode, 1)
            self.assertEqual(json.loads(failed.stdout)["status"], "fail")

            measurements.write_text("{}", encoding="utf-8")
            invalid = subprocess.run(
                [sys.executable, str(SCRIPT), "--measurements", str(measurements), "--thresholds", str(thresholds)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
            self.assertEqual(invalid.returncode, 2)
            self.assertEqual(json.loads(invalid.stdout)["status"], "error")


if __name__ == "__main__":
    unittest.main(verbosity=2)
