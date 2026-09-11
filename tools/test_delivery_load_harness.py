#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
import unittest
from pathlib import Path

from delivery_load_harness import SCENARIOS, build_plan, build_workload, drive_workload

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "tools" / "delivery_load_harness.py"


class DeliveryLoadHarnessTest(unittest.TestCase):
    def test_all_registered_scenarios_are_deterministic(self) -> None:
        for scenario, spec in SCENARIOS.items():
            with self.subTest(scenario=scenario):
                first = build_plan(scenario, operations=250, seed=23)
                second = build_plan(scenario, operations=250, seed=23)
                self.assertEqual(first, second)
                self.assertEqual(build_workload(first), build_workload(second))
                self.assertEqual(first.concurrency, int(spec["concurrency"]))
                self.assertEqual(first.burst_size, int(spec["burst_size"]))
                self.assertEqual(first.quota_fraction, float(spec["quota_fraction"]))
                self.assertEqual(first.fault_mode, str(spec["fault_mode"]))

    def test_workload_is_bounded_and_has_stable_unique_operation_keys(self) -> None:
        plan = build_plan("burst", operations=70, seed=7)
        workload = build_workload(plan)

        self.assertEqual(len(workload), 70)
        self.assertEqual(len({operation.operation_key for operation in workload}), 70)
        self.assertLessEqual(max(operation.slot for operation in workload), plan.concurrency - 1)
        self.assertEqual([operation.batch for operation in workload[:33]], [0] * 32 + [1])

    def test_quota_constrained_schedule_admits_exact_fraction_per_full_batch(self) -> None:
        plan = build_plan("quota-constrained", operations=16, seed=23)
        workload = build_workload(plan)

        first_batch = [operation for operation in workload if operation.batch == 0]
        second_batch = [operation for operation in workload if operation.batch == 1]
        self.assertEqual(sum(operation.quota_allowed for operation in first_batch), 2)
        self.assertEqual(sum(operation.quota_allowed for operation in second_batch), 2)

    def test_driver_executes_each_logical_operation_once_in_reproducible_order(self) -> None:
        plan = build_plan("saturated", operations=9, seed=3)
        seen: list[str] = []

        results = drive_workload(plan, lambda operation: seen.append(operation.operation_key) or operation.index)

        self.assertEqual(results, tuple(range(9)))
        self.assertEqual(seen, [operation.operation_key for operation in build_workload(plan)])
        self.assertTrue(all(operation.fault_mode == "capacity" for operation in build_workload(plan)))

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

    def test_cli_emits_stable_machine_readable_json_and_optional_operation_stream(self) -> None:
        completed = subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--scenario",
                "burst",
                "--operations",
                "3",
                "--seed",
                "7",
                "--emit-operations",
            ],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual(
            payload["plan"],
            {
                "burst_size": 32,
                "concurrency": 16,
                "fault_mode": "none",
                "operations": 3,
                "quota_fraction": 1.0,
                "scenario": "burst",
                "seed": 7,
            },
        )
        self.assertEqual([operation["index"] for operation in payload["operations"]], [0, 1, 2])
        self.assertEqual(len({operation["operation_key"] for operation in payload["operations"]}), 3)


if __name__ == "__main__":
    unittest.main(verbosity=2)
