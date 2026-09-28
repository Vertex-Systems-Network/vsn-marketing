#!/usr/bin/env python3
"""Validate RBT-052 capture structure and internal consistency before publication."""
from __future__ import annotations

import argparse
import json
import math
import re
import sys

SHA = re.compile(r"^[0-9a-f]{40}$")
HASH = re.compile(r"^[0-9a-f]{64}$")
IDENTITY = re.compile(r"^[A-Za-z0-9._-]{1,100}$")
NODE_TYPES = {"condition", "wait", "action"}
FAULTS = {
    "saturated", "staleFenced", "retry", "deadLetter", "cancelled",
    "lateFenced", "unknownReview", "replayPinned", "crossWorkspaceRejected",
}
FAULT_TIMINGS = {"scenario", "unknown_outcome", "replay", "replay_duplicate"}
FORBIDDEN_FIELDS = {"password", "secret", "token", "email", "recipient", "provider_payload", "raw_payload"}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def duration(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value) and value >= 0


def fault_checks(value: object) -> bool:
    return (isinstance(value, dict)
            and all(value.get(name) is True for name in FAULTS)
            and isinstance(value.get("measurements_ms"), dict)
            and all(duration(value["measurements_ms"].get(name)) for name in FAULT_TIMINGS))


def reject_sensitive_fields(value: object) -> None:
    if isinstance(value, dict):
        for key, child in value.items():
            require(isinstance(key, str) and key.lower() not in FORBIDDEN_FIELDS, "sensitive evidence field is forbidden")
            reject_sensitive_fields(child)
    elif isinstance(value, list):
        for child in value:
            reject_sensitive_fields(child)


def validate(document: object) -> None:
    require(isinstance(document, dict), "evidence must be a JSON object")
    reject_sensitive_fields(document)
    require(document.get("schema_version") == 1, "unsupported schema version")
    require(isinstance(document.get("benchmark_id"), str) and bool(IDENTITY.fullmatch(document["benchmark_id"])), "invalid benchmark ID")
    require(isinstance(document.get("source_sha"), str) and bool(SHA.fullmatch(document["source_sha"])), "invalid source SHA")
    require(isinstance(document.get("resource_profile"), str) and bool(IDENTITY.fullmatch(document["resource_profile"])), "invalid resource profile")
    require(isinstance(document.get("runner_image_sha"), str) and bool(HASH.fullmatch(document["runner_image_sha"])), "invalid runner image digest")
    require(isinstance(document.get("cpu_count"), int) and 1 <= document["cpu_count"] <= 256, "invalid CPU count")
    require(isinstance(document.get("memory_mib"), int) and 512 <= document["memory_mib"] <= 1048576, "invalid memory allocation")
    require(isinstance(document.get("fixture_seed"), int) and 1 <= document["fixture_seed"] <= 1000000, "invalid fixture seed")
    require(document.get("provider_latency_measured") is False and document.get("redis_queue_measured") is False and document.get("graph_traversal_measured") is False, "unsupported measurement claim")
    preflight = document.get("preflight")
    require(isinstance(preflight, dict) and preflight.get("preflight") == "passed" and preflight.get("source_sha") == document["source_sha"], "preflight source mismatch")
    require(preflight.get("database") == document.get("database"), "database identity mismatch")
    warmup = document.get("warmup")
    require(isinstance(warmup, dict) and warmup.get("operations") == 20 and duration(warmup.get("elapsed_ms")), "warmup is missing")
    require(fault_checks(warmup.get("fault_checks")), "warmup invariant failed")
    runs = document.get("runs")
    require(isinstance(runs, list) and 2 <= len(runs) <= 5, "expected two to five measured runs")
    graph_hash = None
    expected_operations = None
    expected_concurrency = None
    for run_number, run in enumerate(runs):
        require(isinstance(run, dict), f"run {run_number} is invalid")
        require(isinstance(run.get("graph_hash"), str) and bool(HASH.fullmatch(run["graph_hash"])), "invalid graph hash")
        graph_hash = graph_hash or run["graph_hash"]
        require(run["graph_hash"] == graph_hash, "graph changed between runs")
        count = run.get("operations")
        concurrency = run.get("concurrency")
        require(isinstance(count, int) and 20 <= count <= 1000, "invalid operation count")
        require(isinstance(concurrency, int) and 2 <= concurrency <= 16, "invalid concurrency")
        expected_operations = expected_operations or count
        expected_concurrency = expected_concurrency or concurrency
        require(count == expected_operations and concurrency == expected_concurrency, "run fixture drift")
        require(duration(run.get("elapsed_ms")) and run["elapsed_ms"] > 0 and duration(run.get("throughput_per_second")), "invalid elapsed time or throughput")
        require(run["throughput_per_second"] == round(count * 1000 / max(run["elapsed_ms"], 0.001), 3), "throughput does not match the workload window")
        mix = run.get("node_mix")
        require(isinstance(mix, dict) and set(mix) == NODE_TYPES and sum(mix.values()) == count and all(isinstance(n, int) and n > 0 for n in mix.values()), "invalid node mix")
        faults = run.get("fault_checks")
        require(fault_checks(faults), "fault/replay invariant failed")
        enrollments = run.get("enrollment_samples")
        attempts = run.get("attempt_samples")
        require(isinstance(enrollments, list) and len(enrollments) == count and isinstance(attempts, list) and len(attempts) == count, "raw sample count mismatch")
        require(all(isinstance(item, dict) and isinstance(item.get("index"), int) for item in enrollments + attempts), "invalid raw sample")
        require(sorted(item["index"] for item in enrollments) == list(range(count)), "enrollment sample IDs mismatch")
        require([item["index"] for item in attempts] == list(range(count)), "attempt sample IDs mismatch")
        for sample in enrollments:
            require(sample.get("workspace_slot") in (0, 1) and all(duration(sample.get(key)) for key in ("enroll_ms", "duplicate_ms")), "invalid enrollment sample")
        for sample in attempts:
            require(sample.get("workspace_slot") in (0, 1) and sample.get("node") in NODE_TYPES and sample.get("outcome") == "succeeded", "invalid attempt outcome")
            require(all(duration(sample.get(key)) for key in ("claim_ms", "duplicate_ms", "complete_ms", "end_to_end_ms")), "invalid attempt durations")
        require(run.get("percentile_method") == "nearest_rank", "unsupported percentile method")
        summaries = run.get("latency_percentiles_ms")
        require(isinstance(summaries, dict), "latency summary missing")
        for name, raw, field in (
            ("enroll", enrollments, "enroll_ms"), ("claim", attempts, "claim_ms"),
            ("complete", attempts, "complete_ms"), ("end_to_end", attempts, "end_to_end_ms"),
        ):
            summary = summaries.get(name)
            require(isinstance(summary, dict), "latency percentile missing")
            ordered = sorted(item[field] for item in raw)
            for percentile, fraction in (("p50", 0.50), ("p95", 0.95), ("p99", 0.99)):
                expected = ordered[math.ceil(fraction * count) - 1]
                require(summary.get(percentile) == expected, "latency percentile does not match raw observations")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--stdin", action="store_true", help="Read one JSON document from stdin")
    parser.add_argument("path", nargs="?")
    args = parser.parse_args()
    if args.stdin == (args.path is not None):
        parser.error("provide exactly one of --stdin or path")
    try:
        raw = sys.stdin.read() if args.stdin else open(args.path, encoding="utf-8").read()
        validate(json.loads(raw))
    except (OSError, ValueError, TypeError, KeyError) as exc:
        print(f"TASK-0051 evidence rejected: {exc}", file=sys.stderr)
        return 2
    print("TASK-0051 evidence structure validated")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
