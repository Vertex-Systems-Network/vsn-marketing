#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
import unittest
from pathlib import Path

from delivery_load_harness import SCENARIOS, build_plan

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "tools" / "delivery_load_harness.py"


class DeliveryLoadHarnessTest(unittest.TestCase):
    def test_all_registered_scenarios_are_deterministic(self) -> None:
        for scenario, spec in SCENARIOS.items():
            with self.subTest(scenario=scenario):
                first = build_plan(scenario, operations=250, seed=23)
                second = build_plan(scenario, operations=250, seed=23)
                self.assertEqual(first, second)
                self.assertEqual(first.concurrency, int(spec["concurrency"]))
                self.assertEqual(first.burst_size, int(spec["burst_size"]))
                self.assertEqual(first.quota_fraction, float(spec["quota_fraction"]))
                self.assertEqual(first.fault_mode, str(spec["fault_mode"]))

    def test_rejects_unknown_scenario_and_invalid_inputs(self) -> None:
        with self.assertRaisesRegex(ValueError, "unknown scenario"):
            build_plan("missing", operations=1, seed=0)
        with self.assertRaisesRegex(ValueError, "operations must be a positive integer"):
            build_plan("steady", operations=0, seed=0)
        with self.assertRaisesRegex(ValueError, "operations must be a positive integer"):
            build_plan("steady", operations=True, seed=0)  # type: ignore[arg-type]
        with self.assertRaisesRegex(ValueError, "seed must be a non-negative integer"):
            build_plan("steady", operations=1, seed=-1)
        with self.assertRaisesRegex(ValueError, "seed must be a non-negative integer"):
            build_plan("steady", operations=1, seed=False)  # type: ignore[arg-type]

    def test_cli_emits_stable_machine_readable_json(self) -> None:
        completed = subprocess.run(
            [sys.executable, str(SCRIPT), "--scenario", "burst", "--operations", "42", "--seed", "7"],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)
        self.assertEqual(
            payload,
            {
                "burst_size": 32,
                "concurrency": 16,
                "fault_mode": "none",
                "operations": 42,
                "quota_fraction": 1.0,
                "scenario": "burst",
                "seed": 7,
            },
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
