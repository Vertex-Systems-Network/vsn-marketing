#!/usr/bin/env python3
"""Deterministic TASK-0023 delivery workload harness.

The harness produces a reproducible operation stream and can drive an injected
adapter without making provider calls itself. This keeps load generation
provider-neutral while allowing PostgreSQL/Redis integration workers to execute
the same steady, burst, quota-constrained, and saturated workload definitions.
"""
from __future__ import annotations

import argparse
import json
from dataclasses import asdict, dataclass
from typing import Callable, TypeVar


@dataclass(frozen=True)
class WorkloadPlan:
    scenario: str
    operations: int
    concurrency: int
    burst_size: int
    quota_fraction: float
    fault_mode: str
    seed: int


@dataclass(frozen=True)
class WorkloadOperation:
    index: int
    operation_key: str
    batch: int
    slot: int
    quota_allowed: bool
    fault_mode: str


T = TypeVar("T")
WorkloadDriver = Callable[[WorkloadOperation], T]


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


def build_workload(plan: WorkloadPlan) -> tuple[WorkloadOperation, ...]:
    quota_slots = max(0, min(plan.burst_size, round(plan.burst_size * plan.quota_fraction)))
    operations: list[WorkloadOperation] = []

    for index in range(plan.operations):
        batch = index // plan.burst_size
        position = index % plan.burst_size
        # Rotate each batch by the seed so quota-constrained scenarios do not
        # always privilege the same logical slot while remaining deterministic.
        rotated = (position + plan.seed + batch) % plan.burst_size
        operations.append(
            WorkloadOperation(
                index=index,
                operation_key=f"task-0023-{plan.scenario}-{plan.seed}-{index}",
                batch=batch,
                slot=index % plan.concurrency,
                quota_allowed=rotated < quota_slots,
                fault_mode=plan.fault_mode,
            )
        )

    return tuple(operations)


def drive_workload(plan: WorkloadPlan, driver: WorkloadDriver[T]) -> tuple[T, ...]:
    return tuple(driver(operation) for operation in build_workload(plan))


def main() -> int:
    parser = argparse.ArgumentParser(description="Render a deterministic TASK-0023 delivery workload")
    parser.add_argument("--scenario", choices=sorted(SCENARIOS), required=True)
    parser.add_argument("--operations", type=int, default=1000)
    parser.add_argument("--seed", type=int, default=23)
    parser.add_argument("--emit-operations", action="store_true")
    args = parser.parse_args()

    try:
        plan = build_plan(args.scenario, args.operations, args.seed)
    except ValueError as exc:
        parser.error(str(exc))

    payload: dict[str, object] = {"plan": asdict(plan)}
    if args.emit_operations:
        payload["operations"] = [asdict(operation) for operation in build_workload(plan)]

    print(json.dumps(payload, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
