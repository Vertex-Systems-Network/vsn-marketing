#!/usr/bin/env python3
"""Fail-closed evaluator for TASK-0023 measured delivery SLO evidence."""
from __future__ import annotations

import argparse
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any


@dataclass(frozen=True)
class GateResult:
    metric: str
    passed: bool
    actual: float
    operator: str
    threshold: float


def _number(value: Any, label: str) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ValueError(f"{label} must be numeric")
    return float(value)


def evaluate_metric(metric: str, actual: Any, rule: dict[str, Any]) -> GateResult:
    actual_number = _number(actual, f"measurement {metric}")
    operator = rule.get("operator")
    threshold = _number(rule.get("threshold"), f"threshold {metric}")

    if operator == "max":
        passed = actual_number <= threshold
    elif operator == "min":
        passed = actual_number >= threshold
    else:
        raise ValueError(f"unsupported operator for {metric}: {operator!r}")

    return GateResult(metric, passed, actual_number, str(operator), threshold)


def evaluate(measurements: dict[str, Any], thresholds: dict[str, Any]) -> list[GateResult]:
    if not thresholds:
        raise ValueError("threshold set must not be empty")

    results: list[GateResult] = []
    for metric in sorted(thresholds):
        if metric not in measurements:
            raise ValueError(f"missing required measurement: {metric}")
        rule = thresholds[metric]
        if not isinstance(rule, dict):
            raise ValueError(f"threshold rule for {metric} must be an object")
        results.append(evaluate_metric(metric, measurements[metric], rule))
    return results


def _load_object(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"{path} must contain a JSON object")
    return data


def main() -> int:
    parser = argparse.ArgumentParser(description="Evaluate measured delivery SLO evidence")
    parser.add_argument("--measurements", type=Path, required=True)
    parser.add_argument("--thresholds", type=Path, required=True)
    args = parser.parse_args()

    try:
        results = evaluate(_load_object(args.measurements), _load_object(args.thresholds))
    except (OSError, json.JSONDecodeError, ValueError) as exc:
        print(json.dumps({"status": "error", "reason": str(exc)}, sort_keys=True))
        return 2

    payload = {
        "status": "pass" if all(result.passed for result in results) else "fail",
        "results": [result.__dict__ for result in results],
    }
    print(json.dumps(payload, sort_keys=True, separators=(",", ":")))
    return 0 if payload["status"] == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
