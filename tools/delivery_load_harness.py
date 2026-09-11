#!/usr/bin/env python3
"""Deterministic TASK-0023 delivery workload-plan generator.

This first worker slice defines reproducible scenario inputs only. It does not
claim production capacity and does not send provider traffic.
"""
from __future__ import annotations

import argparse
import json
from dataclasses import asdict, dataclass


@dataclass(frozen=True)
class WorkloadPlan:
    scenario: str
    operations: int
    concurrency: int
    burst_size: int
    quota_fraction: float
    fault_mode: str
    seed: int


SCENARIOS: dict[str, dict[str, object]] = {
    "steady": {"concurrency": 4, "burst_size": 1, "quota_fraction": 1.0, "fault_mode": "none"},
    "burst": {"concurrency": 16, "burst_size": 32, "quota_fraction": 1.0, "fault_mode": "none"},
    "quota-constrained": {"concurrency": 8, "burst_size": 8, "quota_fraction": 0.25, "fault_mode": "none"},
    "saturated": {"concurrency": 32, "burst_size": 64, "quota_fraction": 1.0, "fault_mode": "capacity"},
}


def build_plan(scenario: str, operations: int, seed: int) -> WorkloadPlan:
    if scenario not in SCENARIOS:
        raise ValueError(f"unknown scenario: {scenario}")
    if isinstance(operations, bool) or not isinstance(operations, int) or operations <= 0:
        raise ValueError("operations must be a positive integer")
    if isinstance(seed, bool) or not isinstance(seed, int) or seed < 0:
        raise ValueError("seed must be a non-negative integer")

    spec = SCENARIOS[scenario]
    return WorkloadPlan(
        scenario=scenario,
        operations=operations,
        concurrency=int(spec["concurrency"]),
        burst_size=int(spec["burst_size"]),
        quota_fraction=float(spec["quota_fraction"]),
        fault_mode=str(spec["fault_mode"]),
        seed=seed,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Render a deterministic TASK-0023 workload plan")
    parser.add_argument("--scenario", choices=sorted(SCENARIOS), required=True)
    parser.add_argument("--operations", type=int, default=1000)
    parser.add_argument("--seed", type=int, default=23)
    args = parser.parse_args()

    try:
        plan = build_plan(args.scenario, args.operations, args.seed)
    except ValueError as exc:
        parser.error(str(exc))

    print(json.dumps(asdict(plan), sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
