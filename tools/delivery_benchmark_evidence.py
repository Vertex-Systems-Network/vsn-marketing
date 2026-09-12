#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import math
import re
from pathlib import Path
from typing import Any

SHA_RE = re.compile(r"^[0-9a-f]{40}$")
FORBIDDEN_KEY_FRAGMENTS = (
    "password",
    "secret",
    "credential",
    "access_token",
    "refresh_token",
    "authorization",
    "message_body",
    "recipient_email",
    "recipient_address",
    "idempotency_key",
    "queue_partition",
)
REQUIRED_ENVIRONMENT_STRINGS = (
    "php_version",
    "laravel_version",
    "postgresql_version",
    "redis_version",
)
REQUIRED_SCENARIO_INTS = ("seed", "operation_count", "concurrency")


def _object(value: Any, name: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ValueError(f"{name} must be an object")
    return value


def _string(value: Any, name: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f"{name} must be a non-empty string")
    return value.strip()


def _positive_int(value: Any, name: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value <= 0:
        raise ValueError(f"{name} must be a positive integer")
    return value


def _nonnegative_int(value: Any, name: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise ValueError(f"{name} must be a non-negative integer")
    return value


def _number(value: Any, name: str, *, allow_zero: bool) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ValueError(f"{name} must be numeric")
    number = float(value)
    if not math.isfinite(number):
        raise ValueError(f"{name} must be finite")
    if number < 0 or (not allow_zero and number == 0):
        relation = "non-negative" if allow_zero else "positive"
        raise ValueError(f"{name} must be {relation}")
    return number


def _reject_sensitive_keys(value: Any, path: str = "evidence") -> None:
    if isinstance(value, dict):
        for raw_key, nested in value.items():
            key = str(raw_key).lower()
            if any(fragment in key for fragment in FORBIDDEN_KEY_FRAGMENTS):
                raise ValueError(f"{path}.{raw_key} uses a forbidden sensitive key")
            _reject_sensitive_keys(nested, f"{path}.{raw_key}")
    elif isinstance(value, list):
        for index, nested in enumerate(value):
            _reject_sensitive_keys(nested, f"{path}[{index}]")


def percentile(values: list[float], percentile_value: int) -> float:
    if not values:
        raise ValueError("cannot compute percentile from empty observations")
    if percentile_value <= 0 or percentile_value > 100:
        raise ValueError("percentile must be within 1..100")
    ordered = sorted(values)
    index = max(0, math.ceil((percentile_value / 100) * len(ordered)) - 1)
    return ordered[index]


def summarize_observations(values: list[float]) -> dict[str, float | int]:
    return {
        "count": len(values),
        "p50": percentile(values, 50),
        "p95": percentile(values, 95),
        "p99": percentile(values, 99),
        "max": max(values),
    }


def validate_and_aggregate(document: Any) -> dict[str, Any]:
    root = _object(document, "evidence")
    _reject_sensitive_keys(root)

    if root.get("schema_version") != 1:
        raise ValueError("schema_version must be 1")
    benchmark_id = _string(root.get("benchmark_id"), "benchmark_id")
    commit_sha = _string(root.get("commit_sha"), "commit_sha").lower()
    if not SHA_RE.fullmatch(commit_sha):
        raise ValueError("commit_sha must be a 40-character hexadecimal Git SHA")

    environment = _object(root.get("environment"), "environment")
    normalized_environment: dict[str, Any] = {}
    for field in REQUIRED_ENVIRONMENT_STRINGS:
        normalized_environment[field] = _string(environment.get(field), f"environment.{field}")
    runner = _object(environment.get("runner"), "environment.runner")
    normalized_runner = {
        "os": _string(runner.get("os"), "environment.runner.os"),
        "cpu_count": _positive_int(runner.get("cpu_count"), "environment.runner.cpu_count"),
        "memory_mb": _positive_int(runner.get("memory_mb"), "environment.runner.memory_mb"),
    }
    if "architecture" in runner:
        normalized_runner["architecture"] = _string(
            runner.get("architecture"), "environment.runner.architecture"
        )
    normalized_environment["runner"] = normalized_runner

    scenario = _object(root.get("scenario"), "scenario")
    normalized_scenario: dict[str, Any] = {
        "name": _string(scenario.get("name"), "scenario.name"),
        "fault_mode": _string(scenario.get("fault_mode"), "scenario.fault_mode"),
    }
    for field in REQUIRED_SCENARIO_INTS:
        normalized_scenario[field] = _positive_int(scenario.get(field), f"scenario.{field}")
    normalized_scenario["warmup_seconds"] = _number(
        scenario.get("warmup_seconds"), "scenario.warmup_seconds", allow_zero=True
    )
    normalized_scenario["measurement_window_seconds"] = _number(
        scenario.get("measurement_window_seconds"),
        "scenario.measurement_window_seconds",
        allow_zero=False,
    )

    runs = root.get("runs")
    if not isinstance(runs, list) or len(runs) < 2:
        raise ValueError("runs must contain at least two repeated benchmark runs")

    seen_run_ids: set[str] = set()
    metric_names: set[str] | None = None
    pooled: dict[str, list[float]] = {}
    normalized_runs: list[dict[str, Any]] = []
    throughput_values: list[float] = []
    total_completed = 0
    total_elapsed = 0.0

    for index, raw_run in enumerate(runs):
        run = _object(raw_run, f"runs[{index}]")
        run_id = _string(run.get("run_id"), f"runs[{index}].run_id")
        if run_id in seen_run_ids:
            raise ValueError(f"duplicate run_id: {run_id}")
        seen_run_ids.add(run_id)
        if run.get("warmup_observations_excluded") is not True:
            raise ValueError(f"runs[{index}].warmup_observations_excluded must be true")

        elapsed = _number(run.get("elapsed_seconds"), f"runs[{index}].elapsed_seconds", allow_zero=False)
        completed = _nonnegative_int(
            run.get("completed_operations"), f"runs[{index}].completed_operations"
        )
        if completed > normalized_scenario["operation_count"]:
            raise ValueError(
                f"runs[{index}].completed_operations cannot exceed scenario.operation_count"
            )

        observations = _object(run.get("observations"), f"runs[{index}].observations")
        if not observations:
            raise ValueError(f"runs[{index}].observations must not be empty")
        current_metric_names = set(observations)
        if not all(isinstance(name, str) and name.strip() for name in current_metric_names):
            raise ValueError(f"runs[{index}].observations metric names must be non-empty strings")
        if metric_names is None:
            metric_names = current_metric_names
        elif current_metric_names != metric_names:
            raise ValueError("all repeated runs must contain the same observation metrics")

        run_metrics: dict[str, Any] = {}
        for metric in sorted(current_metric_names):
            raw_values = observations[metric]
            if not isinstance(raw_values, list) or not raw_values:
                raise ValueError(f"runs[{index}].observations.{metric} must be a non-empty list")
            values = [
                _number(
                    value,
                    f"runs[{index}].observations.{metric}[{sample_index}]",
                    allow_zero=True,
                )
                for sample_index, value in enumerate(raw_values)
            ]
            run_metrics[metric] = summarize_observations(values)
            pooled.setdefault(metric, []).extend(values)

        throughput = completed / elapsed
        throughput_values.append(throughput)
        total_completed += completed
        total_elapsed += elapsed
        normalized_runs.append(
            {
                "run_id": run_id,
                "elapsed_seconds": elapsed,
                "completed_operations": completed,
                "throughput_ops_s": throughput,
                "metrics": run_metrics,
            }
        )

    canonical = json.dumps(root, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    evidence_fingerprint = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    pooled_metrics = {
        metric: summarize_observations(values) for metric, values in sorted(pooled.items())
    }

    return {
        "schema_version": 1,
        "status": "evidence_valid",
        "certification_decision": "not_evaluated",
        "thresholds_inferred": False,
        "benchmark_id": benchmark_id,
        "commit_sha": commit_sha,
        "environment": normalized_environment,
        "scenario": normalized_scenario,
        "percentile_method": "nearest_rank_from_raw_observations",
        "run_count": len(normalized_runs),
        "runs": normalized_runs,
        "pooled_metrics": pooled_metrics,
        "throughput_ops_s": {
            "per_run": throughput_values,
            "min": min(throughput_values),
            "max": max(throughput_values),
            "pooled": total_completed / total_elapsed,
        },
        "evidence_fingerprint": evidence_fingerprint,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Validate and aggregate repeated TASK-0024 delivery benchmark evidence "
            "without inferring SLO thresholds."
        )
    )
    parser.add_argument("--input", required=True, type=Path, help="JSON benchmark evidence file")
    args = parser.parse_args(argv)
    try:
        document = json.loads(args.input.read_text(encoding="utf-8"))
        result = validate_and_aggregate(document)
    except (OSError, json.JSONDecodeError, ValueError) as exc:
        print(json.dumps({"status": "error", "error": str(exc)}, sort_keys=True))
        return 2
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
